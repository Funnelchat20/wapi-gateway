<?php

namespace Funnelchat\WapiGateway\Http\Resources\Zapi;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class GroupsResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uid' => Str::before($this['phone'], '-group'),
            'name' => $this['name'] ?? '',
            'image' => $this['image'] ?? ''
        ];
    }
}
