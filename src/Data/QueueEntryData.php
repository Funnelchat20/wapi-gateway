<?php

namespace Funnelchat\WapiGateway\Data;

class QueueEntryData
{
    public function __construct(
        public string $uid,
        public string $phone,
        public string $type = '',
        public string $createdAt = ''
    ) {
    }

    public static function fromZapi(array $payload): self
    {
        return new self($payload['uid'] ?? '', $payload['phone'] ?? '', $payload['type'] ?? '', $payload['createdAt'] ?? '');
    }

    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'phone' => $this->phone,
            'type' => $this->type,
            'createdAt' => $this->createdAt,
        ];
    }
}

