<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class MessageResource
{
    public static function make(array $data): array
    {
        return [
            "sent" => isset($data['messageId']),
            "message" => "",
            "id" => $data['messageId'] ?? "",
            // Provider-agnostic queue id (Z-API returns it as `zaapId`). Needed to
            // dequeue a still-queued message via deleteQueueMessage(). Null when the
            // provider response doesn't carry one.
            "queueId" => $data['zaapId'] ?? null,
            "queueNumber" => ""
        ];
    }
}
