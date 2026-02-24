<?php

namespace Funnelchat\WapiGateway\Data;

class InstanceStatusData
{
    public function __construct(
        public readonly bool $connected,
        public readonly string $accountStatus,
        public readonly ?QrCodeData $qrCode,
        public readonly ?string $phone,
        public readonly string $provider,
    ) {
    }

    public function toArray(): array
    {
        return [
            'connected' => $this->connected,
            'accountStatus' => $this->accountStatus,
            'qrCode' => $this->qrCode?->toArray(),
            'phone' => $this->phone,
            'provider' => $this->provider,
        ];
    }
}
