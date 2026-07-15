<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class MeResource
{
    /**
     * Maps the Z-API `/instances/{id}/token/{token}/device` response.
     *
     * `about` is the account's "Estado/Info" profile text (personal accounts)
     * and IS returned by this endpoint — it was previously discarded here.
     * Consumed by wapi-gateway consumers (e.g. accounts' device-quality
     * scoring, Factor 7 "profile_about") to detect an empty/default profile.
     * FunapiClient reuses this same resource (see FunapiClient::me()), so the
     * mapping also applies to the FunApi/whatsgo provider.
     */
    public static function make(array $data): array
    {
        return [
            "phone" => $data['phone'] ?? "",
            "locale" => "",
            "name" => $data['name'] ?? "",
            "avatar" => $data['imgUrl'] ?? "",
            'isBusiness' => $data['isBusiness'] ?? false,
            "about" => $data['about'] ?? "",
        ];
    }
}
