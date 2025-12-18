<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class RebootResource
{
    public static function make(array $data): array
    {
        return [
            "success" => $data['value'] ?? false,
        ];
    }
}
