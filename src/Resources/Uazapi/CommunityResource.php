<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class CommunityResource
{
    public static function make(array $data): array
    {
        return [
            "id" => $data["id"] ?? $data["ID"] ?? "",
            "name" => $data["name"] ?? $data["Name"] ?? ""
        ];
    }

    public static function collection(array $items): array
    {
        return array_map([self::class, 'make'], $items);
    }
}
