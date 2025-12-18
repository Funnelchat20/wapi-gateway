<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class RebootResource
{
    public static function make(array $data): array
    {
        return [
            "success" => (($data['status'] ?? '') === 'restarted') || ($data['value'] ?? false),
        ];
    }
}
