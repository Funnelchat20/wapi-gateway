<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class GroupResource
{
    public static function make(array $data): array
    {
        // Extract id from phone (remove '-group' suffix)
        $phone = $data["phone"] ?? "";
        $id = str_contains($phone, '-group')
            ? substr($phone, 0, strpos($phone, '-group'))
            : $phone;

        // Filter participants by admin status
        $participants = $data["participants"] ?? [];

        $admins = array_values(array_filter($participants, function($p) {
            return ($p['isAdmin'] ?? false) || ($p['isSuperAdmin'] ?? false);
        }));

        $regularParticipants = array_values(array_filter($participants, function($p) {
            return !($p['isAdmin'] ?? false) && !($p['isSuperAdmin'] ?? false);
        }));

        return [
            "id" => $id,
            "name" => $data['subject'] ?? "",
            "description" => $data['description'] ?? null,
            "image" => $data['image'] ?? "",
            "participants" => array_column($regularParticipants, 'phone'),
            "admins" => array_column($admins, 'phone'),
            "groupInviteLink" => $data["invitationLink"] ?? "",
            "communityId" => $data["communityId"] ?? null,
        ];
    }
}
