<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class ContactResource
{
    public static function make(array $data): array
    {
        return [
            "phone" => $data['phone'] ?? "",
            "name" => $data['name'] ?? "",
            "short" => $data['short'] ?? "",
            "notify" => $data['notify'] ?? "",
            "image" => $data['link'] ?? ""
        ];
    }
}
