<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class ExtensionTokenResource
{
    public static function make(array $data): array
    {
        return [
            'token' => $data['token'] ?? null,
            'expiresAt' => isset($data['expiresAt']) ? (int) $data['expiresAt'] : null,
        ];
    }
}
