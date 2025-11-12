<?php

namespace Funnelchat\WapiGateway\Http\Resources\Uazapi;

use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray($request): array
    {
        return $this->resource;
    }
}
