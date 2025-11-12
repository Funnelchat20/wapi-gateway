<?php

namespace Funnelchat\WapiGateway\Clients;

use Funnelchat\WapiGateway\Contracts\MessagesContract;
use Funnelchat\WapiGateway\Contracts\InstancesContract;
use Illuminate\Support\Facades\Http;

class MetaClient implements MessagesContract, InstancesContract
{
    private string $graph = 'https://graph.facebook.com/v20.0/';

    public function sendText(string $uid, string $token, string $to, string $text, array $options = []): array
    {
        $url = $this->graph . $uid . '/messages';
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text],
        ];
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            return ['error' => $res->json('error', 'Failed to send')];
        }
        return $res->json();
    }

    public function create(int $userId, int $deviceId): array
    {
        return ['error' => 'Not supported'];
    }
}

