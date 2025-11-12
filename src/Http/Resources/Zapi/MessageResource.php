<?php

namespace Funnelchat\WapiGateway\Http\Resources\Zapi;

use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'sent' => isset($this['messageId']),
            'message' => '',
            'id' => $this['messageId'] ?? '',
            'queueNumber' => ''
        ];
    }
}
