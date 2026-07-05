<?php

namespace Funnelchat\WapiGateway\Resources\Meta;

class MessageResource
{
    public static function make(array $data): array
    {
        return [
            'sent' => isset($data['messages'][0]['id']),
            'message' => '',
            'id' => $data['messages'][0]['id'] ?? '',
            // Provider-agnostic queue id. Meta Cloud API has no send queue.
            'queueId' => null,
            'queueNumber' => '',
        ];
    }
}
