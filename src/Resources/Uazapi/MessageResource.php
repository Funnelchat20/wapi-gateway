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
            // Provider-agnostic queue id. UAZAPI has no queue-delete concept
            // (deleteQueueMessage is a no-op), so it's always null here.
            "queueId" => $data['queueId'] ?? null,
            "queueNumber" => ""
        ];
    }
}
