<?php

namespace Funnelchat\WapiGateway\Http\Resources\Zapi;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AdGroupResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this['id'],
            'name' => $this['subGroups'][0]['name'],
            'groupId' => Str::before($this['subGroups'][0]['phone'], '-group')
        ];
    }
}
