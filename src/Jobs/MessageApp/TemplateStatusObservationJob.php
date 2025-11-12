<?php

namespace Funnelchat\WapiGateway\Jobs\MessageApp;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TemplateStatusObservationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private int $template_id,
        private string $status,
        private ?string $error,
        private ?string $message_template_id,
        private ?string $header_handle
    ) {
    }

    public function handle(): void
    {
    }
}
