<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class AdGroupResource
{
    public static function make(array $data): array
    {
        // Get first subgroup data
        $subGroups = $data["subGroups"] ?? [];
        $firstSubGroup = $subGroups[0] ?? [];
        
        // Extract groupId from phone (remove '-group' suffix)
        $phone = $firstSubGroup["phone"] ?? "";
        $groupId = str_contains($phone, '-group')
            ? substr($phone, 0, strpos($phone, '-group'))
            : $phone;

        return [
            "id" => $data["id"] ?? "",
            "name" => $firstSubGroup["name"] ?? "",
            'groupId' => $groupId
        ];
    }

    public static function collection(array $items): array
    {
        return array_map([self::class, 'make'], $items);
    }
}
