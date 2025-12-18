<?php

namespace Funnelchat\WapiGateway\Resources\Uazapi;

class QueueResource
{
    public static function make(array $data): array
    {
        // If no messages, return simple Ok response
        if (!isset($data["messages"]) || empty($data["messages"])) {
            return ["Ok"];
        }

        // Get first 100 chars of each message
        $messages = $data["messages"];
        $first100 = array_map(function($message) {
            $text = $message["message"] ?? $message["Message"] ?? $message["fileUrl"] ?? $message["ImageUrl"] ?? $message["VideoUrl"] ?? "";
            return mb_substr($text, 0, 100);
        }, $messages);

        return [
            "totalMessages" => count($messages),
            "first100" => $first100
        ];
    }
}
