<?php

namespace Funnelchat\WapiGateway\Data;

class QrCodeData
{
    public function __construct(
        public readonly ?string $base64,
        public readonly ?string $url,
        public readonly ?int $expiration,
    ) {
    }

    public function toArray(): array
    {
        return [
            'base64' => $this->base64,
            'url' => $this->url,
            'expiration' => $this->expiration,
        ];
    }
}
