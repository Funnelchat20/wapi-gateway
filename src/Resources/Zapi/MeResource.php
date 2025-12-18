<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class MeResource
{
    public static function make(array $data): array
    {
        return [
            "phone" => $data['phone'] ?? "",
            "locale" => "",
            "name" => $data['name'] ?? "",
            "avatar" => $data['imgUrl'] ?? "",
            'isBusiness' => $data['isBusiness'] ?? false
        ];
    }
}
