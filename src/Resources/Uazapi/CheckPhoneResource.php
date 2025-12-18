<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class CheckPhoneResource
{
    public static function make(array $data): array
    {
        return [
            "result" => $data['exists'] ?? false
        ];
    }
}
