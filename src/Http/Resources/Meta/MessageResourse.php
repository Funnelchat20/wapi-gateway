<?php

namespace Funnelchat\WapiGateway\Http\Resources\Meta;

use Illuminate\Http\Resources\Json\JsonResource;

class MessageResourse extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'sent' => isset($this['messages'][0]['id']) ? true : false,
            'message' => '',
            'id' => $this['messages'][0]['id'] ?? '',
            'queueNumber' => ''
        ];
    }
}
