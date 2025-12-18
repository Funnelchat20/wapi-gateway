<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class MessageResource
{
    public static function make(array $data): array
    {
        // Uazapi returns 'messageid' (lowercase) not 'messageId'
        $messageId = $data['messageid'] ?? $data['messageId'] ?? $data['id'] ?? "";
        
        return [
            "sent" => !empty($messageId),
            "message" => "",
            "id" => $messageId,
            "queueNumber" => ""
        ];
    }
}
