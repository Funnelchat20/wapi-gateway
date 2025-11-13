<?php

namespace Funnelchat\WapiGateway\Data;

class ContactEntryData
{
    public function __construct(
        public string $jid,
        public string $name = '',
        public string $phone = ''
    ) {
    }

    public static function fromUazapi(array $payload): self
    {
        $jid = $payload['jid'] ?? '';
        $name = $payload['name'] ?? $payload['profileName'] ?? '';
        $phone = explode('@', $jid)[0] ?? '';
        return new self($jid, $name, $phone);
    }

    public static function fromZapi(array $payload): self
    {
        $phone = $payload['phone'] ?? '';
        $name = $payload['name'] ?? '';
        return new self($phone . '@s.whatsapp.net', $name, $phone);
    }

    public function toArray(): array
    {
        return ['jid' => $this->jid, 'name' => $this->name, 'phone' => $this->phone];
    }
}

