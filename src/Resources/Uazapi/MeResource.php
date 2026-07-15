<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class MeResource
{
    /**
     * Maps the UAZAPI/FunApi(uazapi-family) `/instance/status` response
     * (`UazapiClient::me()` calls the connection-status endpoint, not a
     * dedicated profile endpoint). That payload does not carry a profile
     * "about"/status-text equivalent to Z-API's `device.about`, so `about`
     * is always empty here — kept for contract parity with `Zapi\MeResource`
     * so consumers (e.g. the device-quality Factor 7 scoring) can rely on
     * the key existing across providers without per-provider branching.
     */
    public static function make(array $data): array
    {
        return [
            "phone" => $data['phone'] ?? $data['owner'] ?? '',
            "locale" => "",
            "name" => $data['profileName'] ?? $data['name'] ?? "",
            "avatar" => $data['profilePicUrl'] ?? $data['imgUrl'] ?? "",
            'isBusiness' => $data['isBusiness'] ?? false,
            "about" => "",
        ];
    }
}
