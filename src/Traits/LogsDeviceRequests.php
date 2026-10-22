<?php

namespace Funnelchat\WapiGateway\Traits;

use Funnelchat\WapiGateway\Jobs\StoreDeviceLogJob;

trait LogsDeviceRequests
{
    /**
     * Return the provider name for logging (e.g. 'zapi', 'uazapi', 'funapi', 'meta').
     */
    abstract protected function getProviderName(): string;

    protected function sanitizeUrl(string $url): string
    {
        return preg_replace('#/token/[^/]+/#', '/token/***/', $url);
    }

    /**
     * A URL as logs may keep it: scheme, host, port and path. The query
     * string, the fragment and any user info are dropped.
     *
     * A signed media URL (S3's X-Amz-Signature, GCS's X-Goog-Signature, a
     * `sig` or `token` param) is a bearer credential for as long as it lives.
     * The provider still receives it whole; only what is logged loses it.
     * Anything that is not, as a whole, an http(s) URL comes back unchanged,
     * and so does a URL with nothing to drop.
     */
    protected function stripUrlSecrets(string $value): string
    {
        if (preg_match('#^(https?://)([^/?\#\s]+)([^?\#\s]*)(?:[?\#]\S*)?$#i', $value, $m) !== 1) {
            return $value;
        }

        $authority = $m[2];
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }

        return $m[1] . $authority . $m[3];
    }

    /**
     * stripUrlSecrets() on every string value, at any depth. Generic on
     * purpose: media URLs travel under a different key per provider (Meta's
     * `image.link`, Z-API's `image`, UAZAPI's `file`, a button header…), and
     * a key list would miss the next one.
     */
    protected function stripUrlSecretsDeep(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        array_walk_recursive($data, function (&$value): void {
            if (is_string($value)) {
                $value = $this->stripUrlSecrets($value);
            }
        });

        return $data;
    }

    protected function redactSensitiveKeys(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $sensitiveKeys = ['token', 'access_token', 'client_token', 'client-token', 'password', 'secret'];

        $redact = function (array $items) use (&$redact, $sensitiveKeys): array {
            foreach ($items as $key => $value) {
                if (is_string($key) && in_array(strtolower($key), $sensitiveKeys, true)) {
                    $items[$key] = '***';
                } elseif (is_array($value)) {
                    $items[$key] = $redact($value);
                }
            }
            return $items;
        };

        return $redact($data);
    }

    protected function logRequest(string $method, string $uid, array $context = [], ?float $startTime = null, $response = null, string $url = '', array $requestPayload = []): void
    {
        $provider = $this->getProviderName();
        $url = $this->sanitizeUrl($url);
        // A provider endpoint that carries our token (masked above) keeps its
        // query: it holds our own params (messageId, phone). Any other URL
        // loses query and fragment, like the URLs inside the payload.
        if (! str_contains($url, '/token/***/')) {
            $url = $this->stripUrlSecrets($url);
        }
        $durationMs = $startTime !== null ? (int)((microtime(true) - $startTime) * 1000) : null;
        $sentAt = $startTime !== null ? date('Y-m-d H:i:s', (int) $startTime) : now()->toDateTimeString();

        $logData = [
            'method' => $method,
            'instance_uid' => $uid,
        ];

        if ($durationMs !== null) {
            $logData['request_time_ms'] = $durationMs;
        }

        if ($response && method_exists($response, 'status')) {
            $logData['status_code'] = $response->status();
            $logData['success'] = $response->successful();
        }

        if (isset($context['error']) && !is_string($context['error'])) {
            $context['error'] = is_array($context['error'])
                ? ($context['error']['message'] ?? $context['error']['reason'] ?? json_encode($context['error']))
                : (json_encode($context['error']) ?: (string) $context['error']);
        }

        $logData = $this->stripUrlSecretsDeep(array_merge($logData, $context));

        if (isset($context['error'])) {
            logger()->error("wapi-gateway.{$provider}.{$method}.error", $logData);
        } else {
            logger()->info("wapi-gateway.{$provider}.{$method}", $logData);
        }

        if (config('wapi-gateway.logging_enabled', false)) {
            try {
                $responseBody = $response && method_exists($response, 'json') ? $response->json() : null;

                $responseBody = $this->truncatePayloadForSqs($this->redactSensitiveKeys($this->stripUrlSecretsDeep($responseBody)));
                $requestPayload = $this->truncatePayloadForSqs($this->redactSensitiveKeys($this->stripUrlSecretsDeep($requestPayload)));

                StoreDeviceLogJob::dispatch(
                    $uid,
                    $provider,
                    $method,
                    $url,
                    $requestPayload,
                    $responseBody,
                    $response && method_exists($response, 'status') ? $response->status() : null,
                    $durationMs,
                    isset($context['error']),
                    $sentAt,
                );
            } catch (\Throwable $e) {
                logger()->error("wapi-gateway.{$provider}.{$method}.log-dispatch-failed", [
                    'instance_uid' => $uid,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Truncate a payload array if its JSON representation exceeds 100KB,
     * to prevent exceeding the SQS 256KB message size limit.
     */
    protected function truncatePayloadForSqs(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $encoded = json_encode($payload);

        if ($encoded === false) {
            return ['_truncated' => true, 'original_size' => 0, 'reason' => 'json_encode_failed'];
        }

        $maxBytes = 100 * 1024; // 100KB

        if (strlen($encoded) > $maxBytes) {
            return ['_truncated' => true, 'original_size' => strlen($encoded)];
        }

        return $payload;
    }
}
