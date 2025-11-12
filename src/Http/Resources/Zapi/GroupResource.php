<?php

namespace Funnelchat\WapiGateway\Http\Resources\Zapi;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupResource extends JsonResource
{
    public function toArray($request): array
    {
        $admins = Arr::where($this['participants'], fn($v) => $v['isAdmin'] == true || $v['isSuperAdmin'] == true);
        $participants = Arr::where($this['participants'], fn($v) => $v['isAdmin'] == false && $v['isSuperAdmin'] == false);
        return [
            'id' => Str::before($this['phone'], '-group'),
            'name' => $this['subject'],
            'description' => $this['description'] ?? null,
            'image' => $this['image'] ?? '',
            'participants' => Arr::pluck($participants, 'phone'),
            'admins' => Arr::pluck($admins, 'phone'),
            'groupInviteLink' => $this['invitationLink'],
            'communityId' => $this['communityId'],
        ];
    }
}
