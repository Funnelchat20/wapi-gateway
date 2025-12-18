<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class QrCodeResource
{
    public static function make(array $data): array
    {
        return [
            "connected" => $data["connected"] ?? false,
            "qrCode" => $data["value"] ?? "",
        ];
    }
}
