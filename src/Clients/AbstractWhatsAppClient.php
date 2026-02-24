<?php

namespace Funnelchat\WapiGateway\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

abstract class AbstractWhatsAppClient
{
    abstract protected function getConfigPrefix(): string;

    protected function buildUrl(string $uid, string $token, string $action): string
    {
        $prefix = $this->getConfigPrefix();
        $template = config("{$prefix}.url_template")
            ?? config("{$prefix}.zapi_url")
            ?? null;

        if (!$template) {
            $base = rtrim((string) config("{$prefix}.base_url"), '/');
            if ($base === '') {
                throw new \RuntimeException("Missing base URL configuration for provider [{$prefix}]");
            }

            $template = $base . '/instances/UID/token/TOKEN/ACTION';
        }

        return str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, $action], $template);
    }

    protected function makeAuthenticatedRequest(string $method, string $url, array $payload = [], array $options = []): Response
    {
        $request = $this->prepareRequest($options);
        $method = strtolower($method);

        if (isset($options['query']) && $method === 'get') {
            $payload = $options['query'];
        }

        if (array_key_exists('body', $options)) {
            $request = $request->withBody(
                $options['body'],
                $options['body_type'] ?? 'application/json'
            );
            $payload = [];
        }

        return $this->dispatchRequest($request, $method, $url, $payload);
    }

    protected function logRequest(string $method, string $uid, array $context = [], ?float $startTime = null, ?Response $response = null): void
    {
        $duration = $startTime ? (int) ((microtime(true) - $startTime) * 1000) : null;

        $payload = array_merge([
            'provider' => $this->getConfigPrefix(),
            'instance_uid' => $uid,
        ], $context);

        if ($duration !== null) {
            $payload['request_time_ms'] = $duration;
        }

        if ($response) {
            $payload['status_code'] = $response->status();
            $payload['success'] = $response->successful();
        }

        $level = isset($context['error']) ? 'warning' : 'info';
        logger()->{$level}("wapi-gateway.{$this->getConfigPrefix()}.{$method}", $payload);
    }

    protected function formatError(string|array|\Throwable|null $error): string
    {
        if (is_array($error)) {
            if (Arr::isAssoc($error) && isset($error['message'])) {
                $error = $error['message'];
            } else {
                $error = implode(', ', array_filter(array_map('strval', $error)));
            }
        }

        if ($error instanceof \Throwable) {
            $error = $error->getMessage();
        }

        $message = trim((string) ($error ?? 'unknown_error'));
        if ($message === '') {
            $message = 'unknown_error';
        }

        return $this->mapErrorCodes($message);
    }

    protected function mapErrorCodes(string $error): string
    {
        return $error;
    }

    private function prepareRequest(array $options): PendingRequest
    {
        $prefix = $this->getConfigPrefix();
        $timeout = $options['timeout'] ?? config("{$prefix}.timeout", 120);
        $request = Http::timeout($timeout);

        if (!empty($options['headers'])) {
            $request = $request->withHeaders($options['headers']);
        }

        if (!empty($options['token'])) {
            $request = $request->withToken($options['token']);
        }

        if (!empty($options['as_json'])) {
            $request = $request->asJson();
        }

        if (!empty($options['retry'])) {
            $maxAttempts = $options['max_attempts'] ?? config("{$prefix}.max_attempts", 2);
            $delay = $options['retry_delay'] ?? config("{$prefix}.retry_delay", 500);
            $customCondition = $options['retry_condition'] ?? null;

            $request = $request->retry(
                $maxAttempts,
                $delay,
                function ($exception) use ($customCondition) {
                    if ($customCondition) {
                        return (bool) $customCondition($exception);
                    }

                    if ($exception instanceof RequestException &&
                        Str::contains($exception->getMessage(), 'cURL error 28')) {
                        return false;
                    }

                    return $exception instanceof ConnectionException;
                },
                false
            );
        }

        return $request;
    }

    private function dispatchRequest(PendingRequest $request, string $method, string $url, array $payload): Response
    {
        return match ($method) {
            'get' => $request->get($url, $payload),
            'delete' => $request->delete($url, $payload),
            'put' => $request->put($url, $payload),
            'patch' => $request->patch($url, $payload),
            default => $request->post($url, $payload),
        };
    }
}
