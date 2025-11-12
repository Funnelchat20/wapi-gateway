<?php

namespace Funnelchat\WapiGateway\Http\Resources\Zapi;

use Illuminate\Http\Resources\Json\JsonResource;

class CheckPhoneResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'result' => $this['exists']
        ];
    }
}
