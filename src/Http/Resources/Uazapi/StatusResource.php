<?php

namespace Funnelchat\WapiGateway\Http\Resources\Uazapi;

use Illuminate\Http\Resources\Json\JsonResource;

class StatusResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'accountStatus' => $this['accountStatus'] ?? '',
            'qrCode' => $this['qrCode'] ?? '',
        ];
    }
}
