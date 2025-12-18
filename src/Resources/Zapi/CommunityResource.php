<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class CommunityResource
{
    public static function make(array $data): array
    {
        return [
            "id" => $data["id"] ?? "",
            "name" => $data["name"] ?? ""
        ];
    }

    public static function collection(array $items): array
    {
        return array_map([self::class, 'make'], $items);
    }
}
