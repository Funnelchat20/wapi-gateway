<?php

namespace Funnelchat\WapiGateway\Clients;

class ZApiLiteClient extends ZApiClient
{
    protected string $configPrefix = 'zapi-lite';

    /**
     * Statuses are published only through Z-API for now. Without these
     * overrides the three status methods inherited from ZApiClient would post
     * them to Z-API Lite, so they refuse explicitly, without calling out.
     */
    public function sendTextStatus(string $uid, string $token, string $text, array $options = []): array
    {
        return ['error' => 'sendTextStatus is not supported by ZApiLite provider'];
    }

    public function sendImageStatus(string $uid, string $token, string $image, ?string $caption = null, array $options = []): array
    {
        return ['error' => 'sendImageStatus is not supported by ZApiLite provider'];
    }

    public function sendVideoStatus(string $uid, string $token, string $video, ?string $caption = null, array $options = []): array
    {
        return ['error' => 'sendVideoStatus is not supported by ZApiLite provider'];
    }
}
