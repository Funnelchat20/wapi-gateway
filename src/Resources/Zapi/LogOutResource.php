<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class LogOutResource
{
    public static function make(array $data): array
    {
        return [
            "result" => ($data['value'] ?? false) ? "Logout request sent to WhatsApp" : "",
        ];
    }
}
