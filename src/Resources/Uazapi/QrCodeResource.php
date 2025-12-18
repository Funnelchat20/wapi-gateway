<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class QrCodeResource
{
    public static function make(array $data): array
    {
        return [
            "connected" => $data["connected"] ?? false,
            "qrCode" => $data["value"] ?? $data["qrcode"] ?? "",
        ];
    }
}
