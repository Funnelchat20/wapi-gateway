<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class LogOutResource
{
    public static function make(array $data): array
    {
        return [
            "result" => (($data['status'] ?? '') === 'disconnected' || ($data['value'] ?? false)) ? "Logout request sent to WhatsApp" : "",
        ];
    }
}
