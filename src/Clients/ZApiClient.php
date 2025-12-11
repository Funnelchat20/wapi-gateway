<?php

namespace Funnelchat\WapiGateway\Clients;

use Funnelchat\WapiGateway\Contracts\MessagesContract;
use Funnelchat\WapiGateway\Contracts\InstancesContract;
use Funnelchat\WapiGateway\Contracts\GroupsContract;
use Funnelchat\WapiGateway\Contracts\ContactsContract;
use Funnelchat\WapiGateway\Contracts\QueueContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

class ZApiClient implements MessagesContract, InstancesContract, GroupsContract, ContactsContract, QueueContract
{
    private const BASE = 'https://api.z-api.io/instances/UID/token/TOKEN/ACTION';

    public function sendText(string $uid, string $token, string $to, string $text, array $options = []): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-text'], self::BASE);
        $payload = ['phone' => $to, 'message' => $text];
        if (isset($options['delayMessage'])) $payload['delayMessage'] = (int) $options['delayMessage'];
        if (isset($options['delayTyping'])) $payload['delayTyping'] = (int) $options['delayTyping'];

        $request = Http::withHeaders(['Client-Token' => config('zapi.client_token')])
            ->timeout(config('zapi.timeout', 120));

        // Apply retry logic if enabled in options
        if ($options['retry'] ?? false) {
            $request = $request->retry(
                config('zapi.max_attempts', 2),
                config('zapi.retry_delay', 500),
                function ($exception, $request) {
                    // Don't retry on timeout (prevents duplicates)
                    if ($exception instanceof \Illuminate\Http\Client\RequestException &&
                        str_contains($exception->getMessage(), 'cURL error 28')) {
                        return false;
                    }
                    // Only retry on ConnectionException
                    return $exception instanceof ConnectionException;
                },
                false
            );
        }

        $res = $request->post($url, $payload);

        if ($res->failed() || $res->json('error')) {
            $error = $this->formatError($res->json('error', 'error'));
            $this->logRequest('sendText', $uid, ['error' => $error, 'phone' => $to], $startTime, $res);
            return ['error' => $error];
        }

        $this->logRequest('sendText', $uid, ['phone' => $to, 'has_retry' => $options['retry'] ?? false], $startTime, $res);
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

    public function status(string $uid, string $token): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'status'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url);
        if ($res->failed()) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function qrCode(string $uid, string $token): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'qr-code/image'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function logout(string $uid, string $token): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'disconnect'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function reboot(string $uid, string $token): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'restart'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function me(string $uid, string $token): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'device'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function checkPhone(string $uid, string $token, string $phone): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'phone-exists/' . $phone], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url);
        if ($res->failed()) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function subscribe(string $uid, string $token): array
    {
        $instanceUid = explode('-', $uid)[0] ?? $uid;
        $url = str_replace(['UID', 'TOKEN'], [$instanceUid, $token], config('zapi.subscription_url'));
        $res = Http::withToken(config('zapi.token'))->post($url);
        if ($res->failed() || $res->json('error')) return ['error' => $res->json('error') ?? 'subscribe_failed'];
        return ['paidTill' => date('Y-m-d H:i:s', ($res->json('due') ?? 0) / 1000)];
    }

    public function unsubscribe(string $uid, string $token): array
    {
        $instanceUid = explode('-', $uid)[0] ?? $uid;
        $disconnectUrl = str_replace(['UID', 'TOKEN', 'ACTION'], [$instanceUid, $token, 'disconnect'], self::BASE);
        $disc = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($disconnectUrl);
        if ($disc->failed() || $disc->json('error')) return ['error' => $disc->json('error') ?? 'disconnect_failed'];
        $url = str_replace(['UID', 'TOKEN'], [$instanceUid, $token], config('zapi.unsubscription_url'));
        $res = Http::withToken(config('zapi.token'))->post($url);
        if ($res->failed() || $res->json('error')) return ['error' => $res->json('error') ?? 'unsubscribe_failed'];
        return ['paidTill' => date('Y-m-d H:i:s', ($res->json('due') ?? 0) / 1000)];
    }

    public function getParticipants(string $uid, string $token, string $phone): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'light-group-metadata/' . $phone], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url);
        if ($res->failed() || $res->json('error') || $res->json('success') === false) return [];
        return $res->json('participants') ?? [];
    }

    public function sendFile(string $uid, string $token, string $to, string $fileUrl, array $options = []): array
    {
        $startTime = microtime(true);
        $ext = strtolower(pathinfo($fileUrl, PATHINFO_EXTENSION));
        $action = $this->mapAction($ext);
        if ($action === 'invalid') return ['error' => 'Invalid file extension'];
        if ($action === 'send-document') $action = $action . '/' . $ext;
        $attr = $this->mapAttr($ext);
        $params = ['phone' => $to, $attr => $fileUrl];
        if (isset($options['fileName'])) $params['fileName'] = $options['fileName'];
        if (isset($options['caption'])) $params['caption'] = $options['caption'];
        if (isset($options['mentioned'])) $params['mentioned'] = $options['mentioned'];
        if (isset($options['delayMessage'])) $params['delayMessage'] = (int) $options['delayMessage'];
        if (isset($options['delayTyping'])) $params['delayTyping'] = (int) $options['delayTyping'];

        // Automatically enable async processing for video files (improves performance and prevents timeouts)
        // Can be explicitly disabled by setting $options['async'] = false
        $isVideo = in_array($ext, ['mp4', 'mov', 'gif']);
        if ($isVideo && !isset($options['async'])) {
            $params['async'] = true;
        } elseif (isset($options['async'])) {
            $params['async'] = (bool) $options['async'];
        }

        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, $action], self::BASE);

        $request = Http::withHeaders(['Client-Token' => config('zapi.client_token')])
            ->timeout(config('zapi.timeout', 120));

        // Apply retry logic if enabled in options
        if ($options['retry'] ?? false) {
            $request = $request->retry(
                config('zapi.max_attempts', 2),
                config('zapi.retry_delay', 500),
                function ($exception, $request) {
                    // Don't retry on timeout (prevents duplicates)
                    if ($exception instanceof \Illuminate\Http\Client\RequestException &&
                        str_contains($exception->getMessage(), 'cURL error 28')) {
                        return false;
                    }
                    // Only retry on ConnectionException
                    return $exception instanceof ConnectionException;
                },
                false
            );
        }

        $res = $request->post($url, $params);

        $context = [
            'phone' => $to,
            'file_type' => $ext,
            'action' => $action,
            'is_async' => $params['async'] ?? false,
            'has_retry' => $options['retry'] ?? false,
        ];

        if ($res->failed() || $res->json('error')) {
            $error = $this->formatError($res->json('error', 'error'));
            $context['error'] = $error;
            $this->logRequest('sendFile', $uid, $context, $startTime, $res);
            return ['error' => $error];
        }

        $this->logRequest('sendFile', $uid, $context, $startTime, $res);
        return $res->json();
    }

    public function sendLocation(string $uid, string $token, string $to, float $lat, float $lng, array $options = []): array
    {
        $params = ['phone' => $to, 'latitude' => $lat, 'longitude' => $lng];
        if (isset($options['name'])) $params['name'] = $options['name'];
        if (isset($options['address'])) $params['address'] = $options['address'];
        if (isset($options['mentioned'])) $params['mentioned'] = $options['mentioned'];
        if (isset($options['delayMessage'])) $params['delayMessage'] = (int) $options['delayMessage'];
        if (isset($options['delayTyping'])) $params['delayTyping'] = (int) $options['delayTyping'];
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-location'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->timeout(120)->post($url, $params);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error', 'error'))];
        }
        return $res->json();
    }

    public function sendButtons(string $uid, string $token, string $to, string $message, array $buttons, array $options = []): array
    {
        $params = ['phone' => $to, 'message' => $message, 'buttonList' => ['buttons' => $buttons]];
        if (isset($options['delayMessage'])) $params['delayMessage'] = (int) $options['delayMessage'];
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-button-list'], self::BASE);

        $request = Http::withHeaders(['Client-Token' => config('zapi.client_token')])
            ->timeout(config('zapi.timeout', 120));

        // Apply retry logic if enabled in options
        if ($options['retry'] ?? false) {
            $request = $request->retry(
                config('zapi.max_attempts', 2),
                config('zapi.retry_delay', 500),
                function ($exception, $request) {
                    if ($exception instanceof \Illuminate\Http\Client\RequestException &&
                        str_contains($exception->getMessage(), 'cURL error 28')) {
                        return false;
                    }
                    return $exception instanceof ConnectionException;
                },
                false
            );
        }

        $res = $request->post($url, $params);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error', 'error'))];
        }
        return $res->json();
    }

    public function sendButtonLink(string $uid, string $token, string $to, string $message, string $url, string $label, array $options = []): array
    {
        $params = ['phone' => $to, 'message' => $message, 'buttonActions' => [['type' => 'URL', 'url' => $url, 'label' => $label]]];
        if (isset($options['delayMessage'])) $params['delayMessage'] = (int) $options['delayMessage'];
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-button-actions'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->timeout(120)->post($url, $params);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error', 'error'))];
        }
        return $res->json();
    }

    public function sendOptionList(string $uid, string $token, string $to, string $message, string $buttonLabel, array $optionsList, array $extra = []): array
    {
        $params = ['phone' => $to, 'message' => $message, 'optionList' => ['options' => $optionsList, 'buttonLabel' => $buttonLabel]];
        if (isset($extra['delayMessage'])) $params['delayMessage'] = (int) $extra['delayMessage'];
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-option-list'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->timeout(120)->post($url, $params);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error', 'error'))];
        }
        return $res->json();
    }

    public function sendPoll(string $uid, string $token, string $to, string $message, array $pollOptions, array $options = []): array
    {
        $params = ['phone' => $to, 'message' => $message, 'poll' => array_map(fn($o) => ['name' => $o], $pollOptions)];
        if (isset($options['pollMaxOptions'])) $params['pollMaxOptions'] = (int) $options['pollMaxOptions'];
        if (isset($options['delayMessage'])) $params['delayMessage'] = (int) $options['delayMessage'];
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-poll'], self::BASE);

        $request = Http::withHeaders(['Client-Token' => config('zapi.client_token')])
            ->timeout(config('zapi.timeout', 120));

        // Apply retry logic if enabled in options
        if ($options['retry'] ?? false) {
            $request = $request->retry(
                config('zapi.max_attempts', 2),
                config('zapi.retry_delay', 500),
                function ($exception, $request) {
                    if ($exception instanceof \Illuminate\Http\Client\RequestException &&
                        str_contains($exception->getMessage(), 'cURL error 28')) {
                        return false;
                    }
                    return $exception instanceof ConnectionException;
                },
                false
            );
        }

        $res = $request->post($url, $params);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error', 'error'))];
        }
        return $res->json();
    }

    public function sendLink(string $uid, string $token, string $to, string $message, string $linkUrl, array $options = []): array
    {
        $params = ['phone' => $to, 'message' => $message, 'linkUrl' => $linkUrl];
        if (isset($options['title'])) $params['title'] = $options['title'];
        if (isset($options['linkDescription'])) $params['linkDescription'] = $options['linkDescription'];
        if (isset($options['image'])) $params['image'] = $options['image'];
        if (isset($options['delayMessage'])) $params['delayMessage'] = (int) $options['delayMessage'];
        if (isset($options['delayTyping'])) $params['delayTyping'] = (int) $options['delayTyping'];
        if (isset($options['mentioned'])) $params['mentioned'] = $options['mentioned'];
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-link'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->timeout(120)->post($url, $params);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error', 'error'))];
        }
        return $res->json();
    }

    public function sendEvent(string $uid, string $token, string $toGroupPhone, array $event, array $options = []): array
    {
        $params = ['phone' => $toGroupPhone, 'event' => $event];
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-event'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->timeout(60)->post($url, $params);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function sendTemplate(string $uid, string $token, string $to, string $name, string $languageCode, array $components): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-message'], self::BASE);
        $payload = ['phone' => $to, 'message' => '[TEMPLATE] ' . $name];
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->timeout(60)->post($url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    private function mapAction(string $ext): string
    {
        return [
            'jpg' => 'send-image', 'jpeg' => 'send-image', 'png' => 'send-image',
            'gif' => 'send-video', 'mp4' => 'send-video', 'mov' => 'send-video',
            'aac' => 'send-audio', 'oga' => 'send-audio', 'ogg' => 'send-audio', 'mp3' => 'send-audio', 'm4a' => 'send-audio', 'opus' => 'send-audio', 'wav' => 'send-audio',
            'pdf' => 'send-document', 'doc' => 'send-document', 'docx' => 'send-document',
            'svg' => 'send-sticker', 'webp' => 'send-sticker'
        ][$ext] ?? 'invalid';
    }

    private function mapAttr(string $ext): string
    {
        return [
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image',
            'gif' => 'video', 'mp4' => 'video', 'mov' => 'video',
            'aac' => 'audio', 'oga' => 'audio', 'ogg' => 'audio', 'mp3' => 'audio', 'm4a' => 'audio', 'opus' => 'audio', 'wav' => 'audio',
            'pdf' => 'document', 'doc' => 'document', 'docx' => 'document',
            'svg' => 'sticker', 'webp' => 'sticker'
        ][$ext] ?? 'invalid';
    }

    public function groups(string $uid, string $token, array $options = []): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'groups'], self::BASE);
        $params = ['page' => $options['page'] ?? 1, 'pageSize' => $options['pageSize'] ?? 299];
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url, $params);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        $filtered = collect($res->collect())->filter(fn($g) => array_key_exists('communityId', $g) && empty($g['communityId']))->unique('phone')->values();
        return $filtered->toArray();
    }

    public function adGroups(string $uid, string $token, array $options = []): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'groups'], self::BASE);
        $params = ['page' => $options['page'] ?? 1, 'pageSize' => $options['pageSize'] ?? 999];
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url, $params);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        $filtered = collect($res->collect())->filter(fn($g) => array_key_exists('communityId', $g) && !empty($g['communityId']))->values();
        return $filtered->toArray();
    }

    public function group(string $uid, string $token, string $id): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'group-metadata/' . $id . '-group'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url);
        if ($res->failed() || $res->json('error') || $res->json('success') === false) return ['error' => $this->formatError($res->json('error', $res->json('message', 'error')))];
        $image = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get(str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'chats/' . $id . '-group'], self::BASE))->json('profileThumbnail', '');
        $data = $res->json();
        $data['image'] = $image;
        return $data;
    }

    public function createGroup(string $uid, string $token, string $name, array $participants, array $options = []): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'create-group'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post($url, ['groupName' => $name, 'phones' => $participants, 'autoInvite' => true]);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        $data = $res->json();
        if (!isset($data['phone'])) return ['error' => 'group_phone_missing'];
        if (!empty($options['admins'])) {
            Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post(str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'add-admin'], self::BASE), ['groupId' => $data['phone'], 'phones' => $options['admins']]);
        }
        if (!empty($options['photo'])) {
            Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post(str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'update-group-photo'], self::BASE), ['groupId' => $data['phone'], 'groupPhoto' => $options['photo']]);
        }
        return $data;
    }

    public function updateGroupName(string $uid, string $token, string $id, string $name): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'update-group-name'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post($url, ['groupId' => $id, 'groupName' => $name]);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function updateGroupDescription(string $uid, string $token, string $id, string $description): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'update-group-description'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post($url, ['groupId' => $id . '-group', 'groupDescription' => $description]);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function updateGroupSettings(string $uid, string $token, string $id, bool $adminOnlyMessage, bool $adminOnlySettings): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'update-group-settings'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post($url, ['phone' => $id . '-group', 'adminOnlyMessage' => $adminOnlyMessage, 'adminOnlySettings' => $adminOnlySettings]);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function updateGroupPhoto(string $uid, string $token, string $id, string $photoUrl): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'update-group-photo'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post($url, ['groupId' => $id, 'groupPhoto' => $photoUrl]);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function addParticipants(string $uid, string $token, string $id, array $phones): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'add-participant'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post($url, ['autoInvite' => true, 'groupId' => $id, 'phones' => $phones]);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function addAdmins(string $uid, string $token, string $id, array $phones): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'add-admin'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post($url, ['groupId' => $id, 'phones' => $phones]);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function removeParticipants(string $uid, string $token, string $id, array $phones): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'remove-participant'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post($url, ['groupId' => $id, 'phones' => $phones]);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function removeAdmins(string $uid, string $token, string $id, array $phones): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'remove-admin'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post($url, ['groupId' => $id, 'phones' => $phones]);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function leaveGroup(string $uid, string $token, string $id): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'leave-group'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post($url, ['groupId' => $id . '-group']);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function contact(string $uid, string $token, string $phone): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'contacts/' . $phone], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('message') ?? $res->json('error', 'error'))];
        return $res->json();
    }

    public function contacts(string $uid, string $token, array $options = []): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'contacts'], self::BASE);
        $params = ['page' => $options['page'] ?? 1, 'pageSize' => $options['pageSize'] ?? 50];
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url, $params);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('message') ?? $res->json('error', 'error'))];
        return $res->collect()->toArray();
    }

    public function sendContact(string $uid, string $token, string $to, string $contactName, string $contactPhone, array $options = []): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-contact'], self::BASE);
        $payload = ['phone' => $to, 'contactName' => $contactName, 'contactPhone' => $contactPhone];
        if (isset($options['delayMessage'])) $payload['delayMessage'] = (int) $options['delayMessage'];
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post($url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function communities(string $uid, string $token, array $options = []): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'communities'], self::BASE);
        $params = ['page' => $options['page'] ?? 1, 'pageSize' => $options['pageSize'] ?? 10];
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url, $params);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function communitiesMetadata(string $uid, string $token, string $id): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'communities-metadata/' . $id], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function community(string $uid, string $token, array $data): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'communities'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post($url, ['name' => $data['name'], 'description' => $data['description'] ?? null]);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        $communityId = $res->json('id');
        Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post(str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'communities/settings'], self::BASE), ['communityId' => $communityId, 'whoCanAddNewGroups' => 'admins']);
        return $res->json();
    }

    public function groupInvitationMetadata(string $uid, string $token, string $url): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'group-invitation-metadata'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url, ['url' => $url]);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('message') ?? $res->json('error', 'error'))];
        return $res->json();
    }

    public function chats(string $uid, string $token, array $options = []): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'chats'], self::BASE);
        $params = [];
        if (isset($options['page'])) $params['page'] = (int) $options['page'];
        if (isset($options['pageSize'])) $params['pageSize'] = (int) $options['pageSize'];
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url, $params);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function deleteChat(string $uid, string $token, string $phone): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'modify-chat'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->post($url, ['phone' => $phone, 'action' => 'delete']);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function deleteMessage(string $uid, string $token, string $messageId, string $phone, bool $owner): array
    {
        $base = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'messages'], self::BASE);
        $query = http_build_query(['messageId' => $messageId, 'phone' => $phone]) . ($owner ? '&owner=true' : '');
        $url = $base . '?' . $query;
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->timeout(20)->delete($url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    /**
     * Log request with performance metrics
     *
     * @param string $method The method name being executed
     * @param string $uid Instance UID
     * @param array $context Additional context data
     * @param float|null $startTime Start time for duration calculation
     * @param mixed $response Response object or data
     * @return void
     */
    private function logRequest(string $method, string $uid, array $context = [], ?float $startTime = null, $response = null): void
    {
        $logData = [
            'method' => $method,
            'instance_uid' => $uid,
        ];

        // Add request duration if start time provided
        if ($startTime !== null) {
            $logData['request_time_ms'] = (int)((microtime(true) - $startTime) * 1000);
        }

        // Add response status if available
        if ($response && method_exists($response, 'status')) {
            $logData['status_code'] = $response->status();
            $logData['success'] = $response->successful();
        }

        // Merge additional context
        $logData = array_merge($logData, $context);

        // Log at appropriate level
        if (isset($context['error'])) {
            logger()->error("wapi-gateway.zapi.{$method}.error", $logData);
        } else {
            logger()->info("wapi-gateway.zapi.{$method}", $logData);
        }
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

    public function showQueue(string $uid, string $token, array $options = []): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'queue'], self::BASE);
        $params = ['page' => $options['page'] ?? 1, 'pageSize' => $options['pageSize'] ?? 499];
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url, $params);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function queueCount(string $uid, string $token): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'queue/count'], self::BASE);
        $res = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['count' => $res->json('count')];
    }

    public function deleteQueueMessage(string $uid, string $token, string $messageQueueUid): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'queue/' . $messageQueueUid], self::BASE);
        $res = Http::withHeaders(['accept' => 'application/json', 'client-token' => env('ZAPI_CLIENT_TOKEN', '')])->delete($url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function clearQueue(string $uid, string $token): array
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'queue'], self::BASE);
        $res = Http::withHeaders(['accept' => 'application/json', 'client-token' => env('ZAPI_CLIENT_TOKEN', '')])->delete($url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    /**
     * Delete multiple messages concurrently using HTTP pool for parallel requests.
     * This provides significantly better performance compared to sequential deletions.
     *
     * @param string $uid Instance UID
     * @param string $token Instance token
     * @param array $deleteRequests Array of deletion requests, each containing:
     *                              - messageId: The message ID to delete
     *                              - phone: The phone number (group or contact)
     *                              - owner: Boolean indicating if message is owned by sender
     * @return array Array of responses indexed by messageId
     */
    public function deleteMessagesConcurrently(string $uid, string $token, array $deleteRequests): array
    {
        $clientToken = config('zapi.client_token');
        $headers = [
            'Content-Type' => 'application/json',
            'Client-Token' => $clientToken
        ];

        // Build all delete requests for the pool
        $requests = collect($deleteRequests)->map(function ($params) use ($uid, $token, $headers) {
            $messageId = $params['messageId'];
            $phone = $params['phone'];
            $owner = $params['owner'] ?? false;

            // Build the URL with query parameters
            $base = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'messages'], self::BASE);
            $query = http_build_query(['messageId' => $messageId, 'phone' => $phone]) . ($owner ? '&owner=true' : '');
            $url = $base . '?' . $query;

            // Return a closure that will be executed in the pool
            return fn($pool) => $pool->as($messageId)
                ->withHeaders($headers)
                ->timeout(config('zapi.timeout', 29))
                ->delete($url);
        });

        logger()->info('wapi-gateway.zapi.delete_messages_concurrently', [
            'instance_uid' => $uid,
            'request_count' => count($deleteRequests),
            'message_ids' => collect($deleteRequests)->pluck('messageId')->toArray()
        ]);

        // Execute all requests in parallel using HTTP pool
        $responses = Http::pool(fn($pool) => $requests->map(fn($req) => $req($pool))->all());

        // Process responses and format errors if needed
        $results = [];
        foreach ($responses as $messageId => $response) {
            if ($response->failed() || $response->json('error')) {
                $results[$messageId] = ['error' => $this->formatError($response->json('error', 'error'))];
            } else {
                $results[$messageId] = $response->json() ?? ['success' => true];
            }
        }

        logger()->info('wapi-gateway.zapi.delete_messages_concurrently.completed', [
            'instance_uid' => $uid,
            'total_requests' => count($deleteRequests),
            'successful' => count(array_filter($results, fn($r) => !isset($r['error']))),
            'failed' => count(array_filter($results, fn($r) => isset($r['error'])))
        ]);

        return $results;
    }
}
