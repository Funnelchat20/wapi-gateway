<?php

namespace Funnelchat\WapiGateway\Http\Resources\Uazapi;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class GroupsResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uid' => Str::before($this['id'] ?? $this['JID'] ?? '', '@g.us'),
            'name' => $this['name'] ?? $this['Name'] ?? '',
            'image' => $this['image'] ?? ''
        ];
    }
}
