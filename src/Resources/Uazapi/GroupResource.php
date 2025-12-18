<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class GroupResource
{
    public static function make(array $data): array
    {
        // Extract id from JID
        $jid = $data["JID"] ?? $data["id"] ?? "";
        $id = str_contains($jid, '@')
            ? substr($jid, 0, strpos($jid, '@'))
            : $jid;

        // Filter participants by admin status
        $participants = $data["Participants"] ?? $data["participants"] ?? [];

        $admins = array_values(array_filter($participants, function($p) {
            return ($p['IsAdmin'] ?? $p['isAdmin'] ?? false) ||
                   ($p['IsSuperAdmin'] ?? $p['isSuperAdmin'] ?? false);
        }));

        $regularParticipants = array_values(array_filter($participants, function($p) {
            return !($p['IsAdmin'] ?? $p['isAdmin'] ?? false) &&
                   !($p['IsSuperAdmin'] ?? $p['isSuperAdmin'] ?? false);
        }));

        // Extract phone numbers from JIDs
        $extractPhone = function($p) {
            $jid = $p['JID'] ?? $p['jid'] ?? '';
            if (preg_match('/^(\d+)[@:]/', $jid, $matches)) {
                return $matches[1];
            }
            return '';
        };

        return [
            "id" => $id,
            "name" => $data['Subject'] ?? $data['subject'] ?? "",
            "description" => $data['Description'] ?? $data['description'] ?? null,
            "image" => $data['PictureUrl'] ?? $data['image'] ?? "",
            "participants" => array_map($extractPhone, $regularParticipants),
            "admins" => array_map($extractPhone, $admins),
            "groupInviteLink" => $data["InviteLink"] ?? $data["invitationLink"] ?? "",
            "communityId" => $data["CommunityId"] ?? $data["communityId"] ?? null,
        ];
    }
}
