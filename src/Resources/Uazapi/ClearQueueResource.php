<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class ClearQueueResource
{
    public static function make(array $data): array
    {
        return [
            "message" => "Cleared 0 messages",
            "messageTextsExample" => []
        ];
    }
}
