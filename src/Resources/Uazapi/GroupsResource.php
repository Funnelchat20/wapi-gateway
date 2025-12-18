<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class GroupsResource
{
    public static function make(array $data): array
    {
        // Extract uid from JID (format: 120363301885188296@g.us)
        $jid = $data["JID"] ?? $data["id"] ?? "";
        $uid = str_contains($jid, '@')
            ? substr($jid, 0, strpos($jid, '@'))
            : $jid;

        return [
            "uid" => $uid,
            "name" => $data['Subject'] ?? $data['name'] ?? "",
            "image" => $data['PictureUrl'] ?? $data['image'] ?? ""
        ];
    }

    public static function collection(array $items): array
    {
        return array_map([self::class, 'make'], $items);
    }
}
