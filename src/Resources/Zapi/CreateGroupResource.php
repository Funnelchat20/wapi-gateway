<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class CreateGroupResource
{
    public static function make(array $data): array
    {
        // Extract chatId from phone (remove '-group' suffix)
        $phone = $data["phone"] ?? "";
        $chatId = "";

        if ($phone) {
            $chatId = str_contains($phone, '-group')
                ? substr($phone, 0, strpos($phone, '-group'))
                : $phone;
        }

        return [
            "created" => isset($data["phone"]),
            "chatId" => $chatId,
            "groupInviteLink" => $data["invitationLink"] ?? ""
        ];
    }
}
