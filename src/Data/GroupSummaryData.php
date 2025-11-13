<?php

namespace Funnelchat\WapiGateway\Data;

class GroupSummaryData
{
    public function __construct(
        public string $uid,
        public string $name = '',
        public string $image = ''
    ) {
    }

    public static function fromZapi(array $payload): self
    {
        $uid = explode('-group', $payload['phone'] ?? '')[0] ?? '';
        return new self($uid, $payload['name'] ?? '', $payload['image'] ?? '');
    }

    public static function fromUazapi(array $payload): self
    {
        $jid = $payload['id'] ?? $payload['JID'] ?? '';
        $uid = str_contains($jid, '@g.us') ? explode('@g.us', $jid)[0] : $jid;
        return new self($uid, $payload['name'] ?? $payload['Name'] ?? '', $payload['image'] ?? '');
    }

    public function toArray(): array
    {
        return ['uid' => $this->uid, 'name' => $this->name, 'image' => $this->image];
    }
}

