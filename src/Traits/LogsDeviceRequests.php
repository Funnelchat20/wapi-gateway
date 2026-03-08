<?php

namespace Funnelchat\WapiGateway\Traits;

use Funnelchat\WapiGateway\Jobs\StoreDeviceLogJob;

trait LogsDeviceRequests
{
    /**
     * Return the provider name for logging (e.g. 'zapi', 'uazapi', 'funapi', 'meta').
     */
    abstract protected function getProviderName(): string;

    /**
     * Log request with performance metrics.
     *
     * @param string $method The method name being executed
     * @param string $uid Instance UID
     * @param array $context Additional context data
     * @param float|null $startTime Start time for duration calculation
     * @param mixed $response Response object or data
     * @param string $url The request URL
     * @param array $requestPayload The request payload
     * @return void
     */
    private function logRequest(string $method, string $uid, array $context = [], ?float $startTime = null, $response = null, string $url = '', array $requestPayload = []): void
    {
        $provider = $this->getProviderName();
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

        $logData = array_merge($logData, $context);

        if (isset($context['error'])) {
            logger()->error("wapi-gateway.{$provider}.{$method}.error", $logData);
        } else {
            logger()->info("wapi-gateway.{$provider}.{$method}", $logData);
        }

        if (config('wapi-gateway.logging_enabled', false)) {
            try {
                $responseBody = $response && method_exists($response, 'json') ? $response->json() : null;

                $responseBody = $this->truncatePayloadForSqs($responseBody);
                $requestPayload = $this->truncatePayloadForSqs($requestPayload);

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
     * Truncate a payload array if its JSON representation exceeds 200KB,
     * to prevent exceeding the SQS 256KB message size limit.
     */
    private function truncatePayloadForSqs(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $encoded = json_encode($payload);

        if ($encoded === false) {
            return ['_truncated' => true, 'original_size' => 0, 'reason' => 'json_encode_failed'];
        }

        $maxBytes = 200 * 1024; // 200KB

        if (strlen($encoded) > $maxBytes) {
            return ['_truncated' => true, 'original_size' => strlen($encoded)];
        }

        return $payload;
    }
}
