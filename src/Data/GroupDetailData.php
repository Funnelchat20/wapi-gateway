<?php

namespace Funnelchat\WapiGateway\Data;

class GroupDetailData
{
    public function __construct(
        public string $id,
        public string $name = '',
        public ?string $description = null,
        public string $image = '',
        public array $participants = [],
        public array $admins = [],
        public ?string $invitationLink = null,
        public ?string $communityId = null
    ) {
    }

    public static function fromZapi(array $payload): self
    {
        $admins = array_values(array_filter($payload['participants'] ?? [], fn($v) => ($v['isAdmin'] ?? false) || ($v['isSuperAdmin'] ?? false)));
        $participants = array_values(array_filter($payload['participants'] ?? [], fn($v) => !($v['isAdmin'] ?? false) && !($v['isSuperAdmin'] ?? false)));
        return new self(
            explode('-group', $payload['phone'] ?? '')[0] ?? '',
            $payload['subject'] ?? '',
            $payload['description'] ?? null,
            $payload['image'] ?? '',
            array_map(fn($p) => $p['phone'], $participants),
            array_map(fn($a) => $a['phone'], $admins),
            $payload['invitationLink'] ?? null,
            $payload['communityId'] ?? null
        );
    }

    public static function fromUazapi(array $payload): self
    {
        $jid = $payload['JID'] ?? $payload['id'] ?? '';
        $id = str_contains($jid, '@g.us') ? explode('@g.us', $jid)[0] : $jid;
        return new self($id, $payload['name'] ?? $payload['Name'] ?? '', null, $payload['image'] ?? '', [], [], null, null);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image' => $this->image,
            'participants' => $this->participants,
            'admins' => $this->admins,
            'invitationLink' => $this->invitationLink,
            'communityId' => $this->communityId,
        ];
    }
}

