<?php

namespace Funnelchat\WapiGateway\Http\Resources\Uazapi;

use Illuminate\Http\Resources\Json\JsonResource;

class ChatResource extends JsonResource
{
    public function toArray($request): array
    {
        return $this->resource;
    }
}
