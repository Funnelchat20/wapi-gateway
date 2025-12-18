<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class ChatResource
{
    public static function make(array $data): array
    {
        return [
            "pinned" => $data["pinned"] ?? false,
            "messagesUnread" => $data["unreadCount"] ?? $data["wa_unreadCount"] ?? 0,
            "unread" => $data["unread"] ?? (($data["unreadCount"] ?? 0) > 0),
            "lastMessageTime" => $data["timestamp"] ?? $data["wa_lastMsgTimestamp"] ?? 0,
            "isGroupAnnouncement" => $data["isGroupAnnouncement"] ?? false,
            "archived" => $data["archived"] ?? false,
            "phone" => $data["id"] ?? $data["wa_chatid"] ?? "",
            "name" => $data["name"] ?? $data["wa_name"] ?? '',
            "isGroup" => $data["isGroup"] ?? $data["wa_isGroup"] ?? false,
            "isMuted" => $data["isMuted"] ?? false,
            "isMarkedSpam" => $data["isMarkedSpam"] ?? false
        ];
    }

    public static function collection(array $items): array
    {
        return array_map([self::class, 'make'], $items);
    }
}
