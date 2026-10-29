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
     *
     * `lid` is the session's own LID (`"<user>@lid"`), returned at the root by
     * Z-API and by FunApi since v3.114.0 (fun-api#223). Consumers persist it as
     * the device's WhatsApp identity. Only present when the provider sends a
     * non-empty value — never an empty string or a made-up one.
     */
    public static function make(array $data): array
    {
        $me = [
            "phone" => $data['phone'] ?? "",
            "locale" => "",
            "name" => $data['name'] ?? "",
            "avatar" => $data['imgUrl'] ?? "",
            'isBusiness' => $data['isBusiness'] ?? false,
            "about" => $data['about'] ?? "",
        ];

        if (is_string($data['lid'] ?? null) && $data['lid'] !== '') {
            $me['lid'] = $data['lid'];
        }

        return $me;
    }
}
