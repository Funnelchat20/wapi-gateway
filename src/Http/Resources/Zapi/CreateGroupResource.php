<?php

namespace Funnelchat\WapiGateway\Http\Resources\Zapi;

use Illuminate\Http\Resources\Json\JsonResource;

class CreateGroupResource extends JsonResource
{
    public function toArray($request): array
    {
        return $this->resource;
    }
}
