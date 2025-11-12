<?php

namespace Funnelchat\WapiGateway\Jobs\MessageApp;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TemplateUpdatedStatusObservationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $template_uid,
        private ?string $observation,
        private ?string $header_handle
    ) {
    }

    public function handle(): void
    {
    }
}
