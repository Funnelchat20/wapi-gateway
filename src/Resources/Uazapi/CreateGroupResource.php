<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class CreateGroupResource
{
    public static function make(array $data): array
    {
        // Extract chatId from JID or phone
        $jid = $data["group"]["JID"] ?? $data["JID"] ?? $data["phone"] ?? "";
        $chatId = "";

        if ($jid) {
            $chatId = str_contains($jid, '@')
                ? substr($jid, 0, strpos($jid, '@'))
                : $jid;
        }

        return [
            "created" => isset($data["group"]["JID"]) || isset($data["JID"]) || isset($data["phone"]),
            "chatId" => $chatId,
            "groupInviteLink" => $data["group"]["InviteLink"] ?? $data["invitationLink"] ?? ""
        ];
    }
}
