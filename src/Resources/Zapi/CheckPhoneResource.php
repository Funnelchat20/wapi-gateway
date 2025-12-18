<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class CheckPhoneResource
{
    public static function make(array $data): array
    {
        return [
            "result" => $data['exists'] ?? false
        ];
    }
}
