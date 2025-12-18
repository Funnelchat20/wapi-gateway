<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class QueuedMessageResource
{
    public static function make(array $data): array
    {
        // Convert timestamp from milliseconds to datetime string
        $created = '';
        if (isset($data["Created"])) {
            $timestamp = (int)$data["Created"];
            $created = date('Y-m-d H:i:s', (int)($timestamp / 1000));
        }

        return [
            "ZaapId" => $data["ZaapId"] ?? "",
            "messageId" => $data["MessageId"] ?? "",
            "message" => $data["Message"] ?? "",
            "created" => $created,
            "phone" => $data["Phone"] ?? "",
            "fileUrl" => $data["ImageUrl"] ?? $data["DocumentUrl"] ?? $data["VideoUrl"] ?? $data["AudioUrl"] ?? "",
            "caption" => $data["Caption"] ?? ""
        ];
    }

    public static function collection(array $items): array
    {
        return array_map([self::class, 'make'], $items);
    }
}
