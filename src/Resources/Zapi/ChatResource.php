<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class ChatResource
{
    public static function make(array $data): array
    {
        return [
            "pinned" => $data["pinned"] ?? false,
            "messagesUnread" => $data["messagesUnread"] ?? 0,
            "unread" => $data["unread"] ?? false,
            "lastMessageTime" => $data["lastMessageTime"] ?? 0,
            "isGroupAnnouncement" => $data["isGroupAnnouncement"] ?? false,
            "archived" => $data["archived"] ?? false,
            "phone" => $data["phone"] ?? "",
            "name" => $data["name"] ?? '',
            "isGroup" => $data["isGroup"] ?? false,
            "isMuted" => $data["isMuted"] ?? false,
            "isMarkedSpam" => $data["isMarkedSpam"] ?? false
        ];
    }

    public static function collection(array $items): array
    {
        return array_map([self::class, 'make'], $items);
    }
}
