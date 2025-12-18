<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class MessageResource
{
    public static function make(array $data): array
    {
        return [
            "sent" => isset($data['messageId']),
            "message" => "",
            "id" => $data['messageId'] ?? "",
            "queueNumber" => ""
        ];
    }
}
