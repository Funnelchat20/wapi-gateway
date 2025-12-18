<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class ContactResource
{
    public static function make(array $data): array
    {
        return [
            "phone" => $data['phone'] ?? "",
            "name" => $data['name'] ?? "",
            "image" => $data['link'] ?? ""
        ];
    }
}
