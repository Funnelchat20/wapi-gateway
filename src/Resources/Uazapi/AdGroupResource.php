<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class AdGroupResource
{
    public static function make(array $data): array
    {
        // Get first subgroup data
        $subGroups = $data["subGroups"] ?? $data["sub_groups"] ?? [];
        $firstSubGroup = $subGroups[0] ?? [];
        
        // Extract groupId from phone or JID
        $phone = $firstSubGroup["phone"] ?? $firstSubGroup["JID"] ?? $firstSubGroup["jid"] ?? "";
        
        // Remove '-group' suffix if present
        $groupId = str_contains($phone, '-group')
            ? substr($phone, 0, strpos($phone, '-group'))
            : $phone;
            
        // Remove @g.us suffix if present
        if (str_contains($groupId, '@g.us')) {
            $groupId = str_replace('@g.us', '', $groupId);
        }

        return [
            "id" => $data["id"] ?? $data["ID"] ?? "",
            "name" => $firstSubGroup["name"] ?? $firstSubGroup["Name"] ?? "",
            'groupId' => $groupId
        ];
    }

    public static function collection(array $items): array
    {
        return array_map([self::class, 'make'], $items);
    }
}
