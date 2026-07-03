<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class SdkConnectorTokenResource
{
    public static function make(array $data): array
    {
        return [
            'token' => $data['token'] ?? null,
        ];
    }
}
