<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class QueuedMessageResource
{
    public static function make(array $data): array
    {
        // Convert timestamp from milliseconds to datetime string
        $created = '';
        if (isset($data["Created"]) || isset($data["created"])) {
            $timestamp = (int)($data["Created"] ?? $data["created"]);
            $created = date('Y-m-d H:i:s', (int)($timestamp / 1000));
        }

        return [
            "ZaapId" => $data["ZaapId"] ?? $data["zaapId"] ?? "",
            "messageId" => $data["MessageId"] ?? $data["messageId"] ?? "",
            "message" => $data["Message"] ?? $data["message"] ?? "",
            "created" => $created,
            "phone" => $data["Phone"] ?? $data["phone"] ?? "",
            "fileUrl" => $data["ImageUrl"] ?? $data["imageUrl"] ?? $data["DocumentUrl"] ?? $data["documentUrl"] ?? $data["VideoUrl"] ?? $data["videoUrl"] ?? $data["AudioUrl"] ?? $data["audioUrl"] ?? "",
            "caption" => $data["Caption"] ?? $data["caption"] ?? ""
        ];
    }

    public static function collection(array $items): array
    {
        return array_map([self::class, 'make'], $items);
    }
}
