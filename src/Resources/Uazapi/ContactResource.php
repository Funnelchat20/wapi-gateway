<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class ContactResource
{
    public static function make(array $data): array
    {
        // Extract phone number from JID (format: 5493765159745@s.whatsapp.net or 5493765159745:72@s.whatsapp.net)
        $jid = $data['jid'] ?? '';
        $phone = '';

        if (preg_match('/^(\d+)[@:]/', $jid, $matches)) {
            $phone = $matches[1];
        }

        return [
            "phone" => $phone,
            "name" => $data['contact_name'] ?? $data['contact_FirstName'] ?? "",
            "image" => "" // UAZ API doesn't provide image URLs in contact list
        ];
    }

    public static function collection(array $items): array
    {
        return array_map([self::class, 'make'], $items);
    }
}
