<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class MeResource
{
    public static function make(array $data): array
    {
        return [
            "phone" => $data['phone'] ?? $data['owner'] ?? '',
            "locale" => "",
            "name" => $data['profileName'] ?? $data['name'] ?? "",
            "avatar" => $data['profilePicUrl'] ?? $data['imgUrl'] ?? "",
            'isBusiness' => $data['isBusiness'] ?? false
        ];
    }
}
