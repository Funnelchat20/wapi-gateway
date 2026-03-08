<?php

namespace Funnelchat\WapiGateway\Jobs;

use Funnelchat\WapiGateway\Models\DeviceLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StoreDeviceLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 10;
    public array $backoff = [5, 15];

    public function __construct(
        private string $instanceUid,
        private string $provider,
        private string $method,
        private string $url,
        private ?array $requestPayload,
        private ?array $responseBody,
        private ?int $statusCode,
        private ?int $durationMs,
        private bool $isError,
        private ?string $sentAt = null,
    ) {
        $this->onQueue('device-logs');
    }

    public function handle(): void
    {
        DeviceLog::create([
            'instance_uid' => $this->instanceUid,
            'provider' => $this->provider,
            'method' => $this->method,
            'url' => $this->url,
            'request_payload' => $this->requestPayload,
            'response_body' => $this->responseBody,
            'status_code' => $this->statusCode,
            'duration_ms' => $this->durationMs,
            'is_error' => $this->isError,
            'sent_at' => $this->sentAt,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        logger()->error('StoreDeviceLogJob failed', [
            'instance_uid' => $this->instanceUid,
            'provider' => $this->provider,
            'method' => $this->method,
            'error' => $exception->getMessage(),
        ]);
    }
}
