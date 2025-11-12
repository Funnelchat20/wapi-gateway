<?php

namespace Funnelchat\WapiGateway\Clients;

use Funnelchat\WapiGateway\Contracts\MessagesContract;
use Funnelchat\WapiGateway\Contracts\InstancesContract;
use Illuminate\Support\Facades\Http;

class UazapiClient implements MessagesContract, InstancesContract
{
    public function sendText(string $uid, string $token, string $to, string $text, array $options = []): array
    {
        $base = config('uazapi.base_url');
        $timeout = config('uazapi.timeout', 120);
        $payload = ['number' => $to, 'text' => $text];
        if (isset($options['delayMessage'])) $payload['delay'] = (int) $options['delayMessage'];
        $res = Http::withHeaders(['token' => $token])->timeout($timeout)->asJson()->post($base . config('uazapi.endpoints.send_message'), $payload);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('message') ?? $res->json('error') ?? 'error')];
        }
        return $res->json();
    }

    public function create(int $userId, int $deviceId): array
    {
        $base = config('uazapi.base_url');
        $timeout = config('uazapi.timeout', 120);
        $name = 'U-' . $userId . ' D-' . $deviceId;
        $res = Http::withHeaders(['admintoken' => config('uazapi.admin_token')])->timeout($timeout)->post($base . '/instance/init', ['name' => $name]);
        if ($res->failed()) {
            return ['error' => $res->json('error', 'Failed to create instance')];
        }
        return [
            'uid' => $res->json('instance.id'),
            'token' => $res->json('instance.token')
        ];
    }

    private function formatError(?string $error): string
    {
        if ($error === null) return 'Unknown error';
        return ucfirst(str_replace(['_', '-'], ' ', strtolower($error)));
    }
}

