<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class GroupsResource
{
    public static function make(array $data): array
    {
        // Extract uid from phone (remove '-group' suffix)
        $phone = $data["phone"] ?? "";
        $uid = str_contains($phone, '-group')
            ? substr($phone, 0, strpos($phone, '-group'))
            : $phone;

        return [
            "uid" => $uid,
            "name" => $data['name'] ?? "",
            "image" => $data['image'] ?? ""
        ];
    }

    public static function collection(array $items): array
    {
        return array_map([self::class, 'make'], $items);
    }
}
