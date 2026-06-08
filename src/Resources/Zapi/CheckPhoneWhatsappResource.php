<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class CheckPhoneWhatsappResource
{
    public static function make(array $data): array
    {
        return [
            "result" => $data['exists'] ?? false,
            "lid" => $data['lid'] ?? null,
            "phone" => $data['phone'] ?? null,
        ];
    }
}
