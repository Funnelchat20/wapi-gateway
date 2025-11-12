<?php

namespace Funnelchat\WapiGateway\Clients;

use Funnelchat\WapiGateway\Contracts\MessagesContract;
use Funnelchat\WapiGateway\Contracts\InstancesContract;
use Illuminate\Support\Facades\Http;

class ZApiClient implements MessagesContract, InstancesContract
{
    private const BASE = 'https://api.z-api.io/instances/UID/token/TOKEN/ACTION';

    public function sendText(string $uid, string $token, string $to, string $text, array $options = []): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-text'], self::BASE);
        $payload = ['phone' => $to, 'message' => $text];
        if (isset($options['delayMessage'])) $payload['delayMessage'] = (int) $options['delayMessage'];
        if (isset($options['delayTyping'])) $payload['delayTyping'] = (int) $options['delayTyping'];
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->timeout(120)->post($url, $payload);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error', 'error'))];
        }
        return $res->json();
    }

    public function create(int $userId, int $deviceId): array
    {
        $name = 'U-' . $userId . ' D-' . $deviceId;
        $baseUrl = config('app.url');
        $res = Http::withToken(config('zapi.token'))->post(config('zapi.on_demand_url'), [
            'name' => $name,
            'sessionName' => 'Funnelchat',
            'receivedCallbackUrl' => $baseUrl . '/webhooks/zapi/received?userId=' . $userId . '&deviceId=' . $deviceId,
            'receivedAndDeliveryCallbackUrl' => $baseUrl . '/webhooks/zapi/received-and-delivery?userId=' . $userId . '&deviceId=' . $deviceId,
            'disconnectedCallbackUrl' => $baseUrl . '/webhooks/zapi/disconnected?userId=' . $userId . '&deviceId=' . $deviceId,
            'connectedCallbackUrl' => $baseUrl . '/webhooks/zapi/connected?userId=' . $userId . '&deviceId=' . $deviceId,
            'messageStatusCallbackUrl' => $baseUrl . '/webhooks/zapi/message-status?userId=' . $userId . '&deviceId=' . $deviceId,
            'blockCallbackUrl' => $baseUrl . '/webhooks/zapi/block?userId=' . $userId . '&deviceId=' . $deviceId,
        ]);
        if ($res->failed()) {
            return ['error' => $res->json('error', 'Failed to create instance')];
        }
        return ['uid' => $res->json('id'), 'token' => $res->json('token')];
    }

    private function formatError(string $error): string
    {
        return match ($error) {
            'Instance not found' => 'instance_not_found',
            'To continue sending a message, you must subscribe to this instance again' => 'pending_subscription',
            'You need to be connected with whatsapp' => 'disconnected_device',
            'Whatsapp not connected' => 'not_connected',
            'Whatsapp did not respond' => 'not_respond',
            'Instance not initialize' => 'not_initialize',
            'GROUP_ERROR_PHONE', 'GROUP_FORBIDDEN' => 'group_forbidden',
            'Phone not exists', 'Phone is wrong', 'Invalid phone' => 'phone_not_exists',
            default => $error,
        };
    }
}

