<?php

namespace Funnelchat\WapiGateway\Clients;

use Funnelchat\WapiGateway\Contracts\MessagesContract;
use Funnelchat\WapiGateway\Contracts\InstancesContract;
use Funnelchat\WapiGateway\Contracts\GroupsContract;
use Funnelchat\WapiGateway\Contracts\ContactsContract;
use Funnelchat\WapiGateway\Contracts\QueueContract;
use Funnelchat\WapiGateway\Resources\Zapi\MessageResource;
use Funnelchat\WapiGateway\Resources\Zapi\QrCodeResource;
use Funnelchat\WapiGateway\Resources\Zapi\MeResource;
use Funnelchat\WapiGateway\Resources\Zapi\LogOutResource;
use Funnelchat\WapiGateway\Resources\Zapi\RebootResource;
use Funnelchat\WapiGateway\Resources\Zapi\CheckPhoneResource;
use Funnelchat\WapiGateway\Resources\Zapi\ContactResource;
use Funnelchat\WapiGateway\Resources\Zapi\GroupsResource;
use Funnelchat\WapiGateway\Resources\Zapi\GroupResource;
use Funnelchat\WapiGateway\Resources\Zapi\CreateGroupResource;
use Funnelchat\WapiGateway\Traits\LogsDeviceRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

class ZApiClient implements MessagesContract, InstancesContract, GroupsContract, ContactsContract, QueueContract
{
    use LogsDeviceRequests;

    protected string $configPrefix = 'zapi';

    protected function getProviderName(): string
    {
        return 'zapi';
    }

    protected function baseUrl(): string
    {
        return rtrim(config("$this->configPrefix.base_url", 'https://api.z-api.io'), '/') . '/instances/UID/token/TOKEN/ACTION';
    }

    // Status constants for compatibility with WAPI
    protected const YOU_ARE_NOT_CONNECTED = 'You are not connected.';
    protected const YOU_ARE_ALREADY_CONNECTED = 'You are already connected.';
    protected const PENDING_SUBSCRIPTION = 'To continue sending a message, you must subscribe to this instance again';
    protected const INSTANCE_STATUSES = [self::YOU_ARE_ALREADY_CONNECTED, self::YOU_ARE_NOT_CONNECTED];
    protected const QR_CODE_RETRIEVAL_ERROR_MESSAGE = 'Error retrieving QR code.';

    public function sendText(string $uid, string $token, string $to, string $text, array $options = []): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-text'], $this->baseUrl());
        $payload = ['phone' => $to, 'message' => $text];
        if (isset($options['delayMessage'])) $payload['delayMessage'] = (int) $options['delayMessage'];
        if (isset($options['delayTyping'])) $payload['delayTyping'] = (int) $options['delayTyping'];

        $request = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])
            ->timeout(config("$this->configPrefix.timeout", 120));

        // Apply retry logic if enabled in options
        if ($options['retry'] ?? false) {
            $request = $request->retry(
                config("$this->configPrefix.max_attempts", 2),
                config("$this->configPrefix.retry_delay", 500),
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
            $this->logRequest('sendText', $uid, ['error' => $error, 'phone' => $to], $startTime, $res, $url, $payload);
            return ['error' => $error];
        }

        $this->logRequest('sendText', $uid, ['phone' => $to, 'has_retry' => $options['retry'] ?? false], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function create(int $userId, int $deviceId): array
    {
        $startTime = microtime(true);
        $name = 'U-' . $userId . ' D-' . $deviceId;
        $webhookBaseUrl = config("$this->configPrefix.webhook_base_url");

        $payload = [
            'name' => $name,
            'sessionName' => 'Funnelchat',
        ];

        // Only configure webhooks if WEBHOOK_BASE_URL is set
        if ($webhookBaseUrl) {
            $payload['receivedCallbackUrl'] = $webhookBaseUrl . '/webhooks/zapi/received?userId=' . $userId . '&deviceId=' . $deviceId;
            $payload['receivedAndDeliveryCallbackUrl'] = $webhookBaseUrl . '/webhooks/zapi/received-and-delivery?userId=' . $userId . '&deviceId=' . $deviceId;
            $payload['disconnectedCallbackUrl'] = $webhookBaseUrl . '/webhooks/zapi/disconnected?userId=' . $userId . '&deviceId=' . $deviceId;
            $payload['connectedCallbackUrl'] = $webhookBaseUrl . '/webhooks/zapi/connected?userId=' . $userId . '&deviceId=' . $deviceId;
            $payload['messageStatusCallbackUrl'] = $webhookBaseUrl . '/webhooks/zapi/message-status?userId=' . $userId . '&deviceId=' . $deviceId;
            $payload['blockCallbackUrl'] = $webhookBaseUrl . '/webhooks/zapi/block?userId=' . $userId . '&deviceId=' . $deviceId;
        }

        $url = config("$this->configPrefix.on_demand_url");
        $res = Http::withToken(config("$this->configPrefix.token"))->post($url, $payload);
        $this->logRequest('create', "U-{$userId}-D-{$deviceId}", $res->failed() ? ['error' => $res->json('error', 'Failed to create instance')] : [], $startTime, $res, $url, $payload);

        if ($res->failed()) {
            return ['error' => $res->json('error', 'Failed to create instance')];
        }

        return ['uid' => $res->json('id'), 'token' => $res->json('token')];
    }

    public function status(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'status'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url);
        $this->logRequest('status', $uid, $res->failed() ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);

        // Handle failures - check for PENDING_SUBSCRIPTION special case
        if ($res->failed()) {
            $error = $res->json('error');

            // Special handling for PENDING_SUBSCRIPTION
            if ($error === self::PENDING_SUBSCRIPTION) {
                return ['accountStatus' => $this->formatError(self::PENDING_SUBSCRIPTION)];
            }

            // If error exists and it's not a recognized instance status, return error
            if ($error && !in_array($error, self::INSTANCE_STATUSES)) {
                return ['error' => $this->formatError($error)];
            }
        }

        $data = $res->json();

        // ALWAYS add accountStatus field (WAPI compatibility)
        $accountStatus = 'authenticated';
        $qrCode = '';

        // Special handling for "You are not connected"
        if (isset($data['error']) && $data['error'] === self::YOU_ARE_NOT_CONNECTED) {
            $accountStatus = 'got qr code';

            // MAKE SECOND API CALL to get QR code automatically
            $qrUrl = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'qr-code/image'], $this->baseUrl());
            $qrRes = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($qrUrl);

            if ($qrRes->failed() || $qrRes->json('error')) {
                return ['error' => self::QR_CODE_RETRIEVAL_ERROR_MESSAGE];
            }

            if (!isset($qrRes->json()['value'])) {
                return ['error' => self::QR_CODE_RETRIEVAL_ERROR_MESSAGE];
            }

            $qrCode = $qrRes->json()['value'];
        }

        // Return ONLY the fields that WAPI original returns (StatusResource)
        return [
            'accountStatus' => $accountStatus,
            'qrCode' => $qrCode,
        ];
    }

    public function qrCode(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'qr-code/image'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url);
        $this->logRequest('qrCode', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return QrCodeResource::make($res->json());
    }

    public function logout(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'disconnect'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url);
        $this->logRequest('logout', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return LogOutResource::make($res->json());
    }

    public function reboot(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'restart'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url);
        $this->logRequest('reboot', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return RebootResource::make($res->json());
    }

    public function me(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'device'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url);
        $this->logRequest('me', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return MeResource::make($res->json());
    }

    public function checkPhone(string $uid, string $token, string $phone): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'phone-exists/' . $phone], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url);
        $this->logRequest('checkPhone', $uid, $res->failed() ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed()) return ['error' => $this->formatError($res->json('error', 'error'))];
        return CheckPhoneResource::make($res->json());
    }

    public function subscribe(string $uid, string $token): array
    {
        $instanceUid = explode('-', $uid)[0] ?? $uid;
        $url = str_replace(['UID', 'TOKEN'], [$instanceUid, $token], config("$this->configPrefix.subscription_url"));
        $res = Http::withToken(config("$this->configPrefix.token"))->post($url);
        if ($res->failed() || $res->json('error')) return ['error' => $res->json('error') ?? 'subscribe_failed'];
        return ['paidTill' => date('Y-m-d H:i:s', ($res->json('due') ?? 0) / 1000)];
    }

    public function unsubscribe(string $uid, string $token): array
    {
        $instanceUid = explode('-', $uid)[0] ?? $uid;
        $disconnectUrl = str_replace(['UID', 'TOKEN', 'ACTION'], [$instanceUid, $token, 'disconnect'], $this->baseUrl());
        $disc = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($disconnectUrl);
        if ($disc->failed() || $disc->json('error')) return ['error' => $disc->json('error') ?? 'disconnect_failed'];
        $url = str_replace(['UID', 'TOKEN'], [$instanceUid, $token], config("$this->configPrefix.unsubscription_url"));
        $res = Http::withToken(config("$this->configPrefix.token"))->post($url);
        if ($res->failed() || $res->json('error')) return ['error' => $res->json('error') ?? 'unsubscribe_failed'];
        return ['paidTill' => date('Y-m-d H:i:s', ($res->json('due') ?? 0) / 1000)];
    }

    public function getParticipants(string $uid, string $token, string $phone): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'light-group-metadata/' . $phone], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url);
        $this->logRequest('getParticipants', $uid, $res->failed() || $res->json('error') || $res->json('success') === false ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
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

        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, $action], $this->baseUrl());

        $request = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])
            ->timeout(config("$this->configPrefix.timeout", 120));

        // Apply retry logic if enabled in options
        if ($options['retry'] ?? false) {
            $request = $request->retry(
                config("$this->configPrefix.max_attempts", 2),
                config("$this->configPrefix.retry_delay", 500),
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
            $this->logRequest('sendFile', $uid, $context, $startTime, $res, $url, $params);
            return ['error' => $error];
        }

        $this->logRequest('sendFile', $uid, $context, $startTime, $res, $url, $params);
        return MessageResource::make($res->json());
    }

    public function sendLocation(string $uid, string $token, string $to, float $lat, float $lng, array $options = []): array
    {
        $startTime = microtime(true);
        $params = ['phone' => $to, 'latitude' => $lat, 'longitude' => $lng];
        if (isset($options['name'])) $params['name'] = $options['name'];
        if (isset($options['address'])) $params['address'] = $options['address'];
        if (isset($options['mentioned'])) $params['mentioned'] = $options['mentioned'];
        if (isset($options['delayMessage'])) $params['delayMessage'] = (int) $options['delayMessage'];
        if (isset($options['delayTyping'])) $params['delayTyping'] = (int) $options['delayTyping'];
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-location'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(120)->post($url, $params);
        $this->logRequest('sendLocation', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $params);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error', 'error'))];
        }
        return MessageResource::make($res->json());
    }

    public function sendButtons(string $uid, string $token, string $to, string $message, array $buttons, array $options = []): array
    {
        $startTime = microtime(true);
        $params = ['phone' => $to, 'message' => $message, 'buttonList' => ['buttons' => $buttons]];
        if (isset($options['delayMessage'])) $params['delayMessage'] = (int) $options['delayMessage'];
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-button-list'], $this->baseUrl());

        $request = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])
            ->timeout(config("$this->configPrefix.timeout", 120));

        // Apply retry logic if enabled in options
        if ($options['retry'] ?? false) {
            $request = $request->retry(
                config("$this->configPrefix.max_attempts", 2),
                config("$this->configPrefix.retry_delay", 500),
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
        $this->logRequest('sendButtons', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $params);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error', 'error'))];
        }
        return $res->json();
    }

    public function sendButtonLink(string $uid, string $token, string $to, string $message, string $url, string $label, array $options = []): array
    {
        $startTime = microtime(true);
        $params = ['phone' => $to, 'message' => $message, 'buttonActions' => [['type' => 'URL', 'url' => $url, 'label' => $label]]];
        if (isset($options['delayMessage'])) $params['delayMessage'] = (int) $options['delayMessage'];
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-button-actions'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(120)->post($url, $params);
        $this->logRequest('sendButtonLink', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $params);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error', 'error'))];
        }
        return $res->json();
    }

    public function sendOptionList(string $uid, string $token, string $to, string $message, string $buttonLabel, array $optionsList, array $extra = []): array
    {
        $startTime = microtime(true);
        $params = ['phone' => $to, 'message' => $message, 'optionList' => ['options' => $optionsList, 'buttonLabel' => $buttonLabel]];
        if (isset($extra['delayMessage'])) $params['delayMessage'] = (int) $extra['delayMessage'];
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-option-list'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(120)->post($url, $params);
        $this->logRequest('sendOptionList', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $params);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error', 'error'))];
        }
        return $res->json();
    }

    public function sendPoll(string $uid, string $token, string $to, string $message, array $pollOptions, array $options = []): array
    {
        $startTime = microtime(true);
        $params = ['phone' => $to, 'message' => $message, 'poll' => array_map(fn($o) => ['name' => $o], $pollOptions)];
        if (isset($options['pollMaxOptions'])) $params['pollMaxOptions'] = (int) $options['pollMaxOptions'];
        if (isset($options['delayMessage'])) $params['delayMessage'] = (int) $options['delayMessage'];
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-poll'], $this->baseUrl());

        $request = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])
            ->timeout(config("$this->configPrefix.timeout", 120));

        // Apply retry logic if enabled in options
        if ($options['retry'] ?? false) {
            $request = $request->retry(
                config("$this->configPrefix.max_attempts", 2),
                config("$this->configPrefix.retry_delay", 500),
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
        $this->logRequest('sendPoll', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $params);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error', 'error'))];
        }
        return $res->json();
    }

    public function sendLink(string $uid, string $token, string $to, string $message, string $linkUrl, array $options = []): array
    {
        $startTime = microtime(true);
        $params = ['phone' => $to, 'message' => $message, 'linkUrl' => $linkUrl];
        if (isset($options['title'])) $params['title'] = $options['title'];
        if (isset($options['linkDescription'])) $params['linkDescription'] = $options['linkDescription'];
        if (isset($options['image'])) $params['image'] = $options['image'];
        if (isset($options['delayMessage'])) $params['delayMessage'] = (int) $options['delayMessage'];
        if (isset($options['delayTyping'])) $params['delayTyping'] = (int) $options['delayTyping'];
        if (isset($options['mentioned'])) $params['mentioned'] = $options['mentioned'];
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-link'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(120)->post($url, $params);
        $this->logRequest('sendLink', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $params);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error', 'error'))];
        }
        return $res->json();
    }

    public function sendEvent(string $uid, string $token, string $toGroupPhone, array $event, array $options = []): array
    {
        $startTime = microtime(true);
        $params = ['phone' => $toGroupPhone, 'event' => $event];
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-event'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(60)->post($url, $params);
        $this->logRequest('sendEvent', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $params);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function sendTemplate(string $uid, string $token, string $to, string $name, string $languageCode, array $components): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-message'], $this->baseUrl());
        $payload = ['phone' => $to, 'message' => '[TEMPLATE] ' . $name];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(60)->post($url, $payload);
        $this->logRequest('sendTemplate', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    protected function mapAction(string $ext): string
    {
        return [
            'jpg' => 'send-image', 'jpeg' => 'send-image', 'png' => 'send-image',
            'gif' => 'send-video', 'mp4' => 'send-video', 'mov' => 'send-video',
            'aac' => 'send-audio', 'oga' => 'send-audio', 'ogg' => 'send-audio', 'mp3' => 'send-audio', 'm4a' => 'send-audio', 'opus' => 'send-audio', 'wav' => 'send-audio',
            'pdf' => 'send-document', 'doc' => 'send-document', 'docx' => 'send-document',
            'svg' => 'send-sticker', 'webp' => 'send-sticker'
        ][$ext] ?? 'invalid';
    }

    protected function mapAttr(string $ext): string
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
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'groups'], $this->baseUrl());
        $params = ['page' => $options['page'] ?? 1, 'pageSize' => $options['pageSize'] ?? 299];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url, $params);
        $this->logRequest('groups', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        $filtered = collect($res->collect())->filter(fn($g) => array_key_exists('communityId', $g) && empty($g['communityId']))->unique('phone')->values();
        return GroupsResource::collection($filtered->toArray());
    }

    public function adGroups(string $uid, string $token, array $options = []): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'groups'], $this->baseUrl());
        $params = ['page' => $options['page'] ?? 1, 'pageSize' => $options['pageSize'] ?? 999];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url, $params);
        $this->logRequest('adGroups', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        $filtered = collect($res->collect())->filter(fn($g) => array_key_exists('communityId', $g) && !empty($g['communityId']))->values();
        return $filtered->toArray();
    }

    public function group(string $uid, string $token, string $id): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'group-metadata/' . $id . '-group'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url);
        $this->logRequest('group', $uid, $res->failed() || $res->json('error') || $res->json('success') === false ? ['error' => $res->json('error', $res->json('message', 'error'))] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error') || $res->json('success') === false) return ['error' => $this->formatError($res->json('error', $res->json('message', 'error')))];
        $image = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get(str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'chats/' . $id . '-group'], $this->baseUrl()))->json('profileThumbnail', '');
        $data = $res->json();
        $data['image'] = $image;
        return GroupResource::make($data);
    }

    public function createGroup(string $uid, string $token, string $name, array $participants, array $options = []): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'create-group'], $this->baseUrl());
        $payload = ['groupName' => $name, 'phones' => $participants, 'autoInvite' => true];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($url, $payload);
        $this->logRequest('createGroup', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        $data = $res->json();
        if (!isset($data['phone'])) return ['error' => 'group_phone_missing'];
        if (!empty($options['admins'])) {
            Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post(str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'add-admin'], $this->baseUrl()), ['groupId' => $data['phone'], 'phones' => $options['admins']]);
        }
        if (!empty($options['photo'])) {
            Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post(str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'update-group-photo'], $this->baseUrl()), ['groupId' => $data['phone'], 'groupPhoto' => $options['photo']]);
        }
        return CreateGroupResource::make($data);
    }

    public function updateGroupName(string $uid, string $token, string $id, string $name): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'update-group-name'], $this->baseUrl());
        $payload = ['groupId' => $id, 'groupName' => $name];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($url, $payload);
        $this->logRequest('updateGroupName', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function updateGroupDescription(string $uid, string $token, string $id, string $description): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'update-group-description'], $this->baseUrl());
        $payload = ['groupId' => $id . '-group', 'groupDescription' => $description];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($url, $payload);
        $this->logRequest('updateGroupDescription', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function updateGroupSettings(string $uid, string $token, string $id, bool $adminOnlyMessage, bool $adminOnlySettings): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'update-group-settings'], $this->baseUrl());
        $payload = ['phone' => $id . '-group', 'adminOnlyMessage' => $adminOnlyMessage, 'adminOnlySettings' => $adminOnlySettings];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($url, $payload);
        $this->logRequest('updateGroupSettings', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function updateGroupPhoto(string $uid, string $token, string $id, string $photoUrl): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'update-group-photo'], $this->baseUrl());
        $payload = ['groupId' => $id, 'groupPhoto' => $photoUrl];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($url, $payload);
        $this->logRequest('updateGroupPhoto', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function addParticipants(string $uid, string $token, string $id, array $phones): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'add-participant'], $this->baseUrl());
        $payload = ['autoInvite' => true, 'groupId' => $id, 'phones' => $phones];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($url, $payload);
        $this->logRequest('addParticipants', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function addAdmins(string $uid, string $token, string $id, array $phones): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'add-admin'], $this->baseUrl());
        $payload = ['groupId' => $id, 'phones' => $phones];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($url, $payload);
        $this->logRequest('addAdmins', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function addCommunityAdmins(string $uid, string $token, string $id, array $phones): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'add-admin'], $this->baseUrl());
        $payload = ['communityId' => $id, 'phones' => $phones];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($url, $payload);
        $this->logRequest('addCommunityAdmins', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function removeParticipants(string $uid, string $token, string $id, array $phones): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'remove-participant'], $this->baseUrl());
        $payload = ['groupId' => $id, 'phones' => $phones];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($url, $payload);
        $this->logRequest('removeParticipants', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function removeAdmins(string $uid, string $token, string $id, array $phones): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'remove-admin'], $this->baseUrl());
        $payload = ['groupId' => $id, 'phones' => $phones];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($url, $payload);
        $this->logRequest('removeAdmins', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function removeCommunityAdmins(string $uid, string $token, string $id, array $phones): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'remove-admin'], $this->baseUrl());
        $payload = ['communityId' => $id, 'phones' => $phones];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($url, $payload);
        $this->logRequest('removeCommunityAdmins', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function leaveGroup(string $uid, string $token, string $id): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'leave-group'], $this->baseUrl());
        $payload = ['groupId' => $id . '-group'];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($url, $payload);
        $this->logRequest('leaveGroup', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function contact(string $uid, string $token, string $phone): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'contacts/' . $phone], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url);
        $this->logRequest('contact', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('message') ?? $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('message') ?? $res->json('error', 'error'))];
        return ContactResource::make($res->json());
    }

    public function contacts(string $uid, string $token, array $options = []): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'contacts'], $this->baseUrl());
        $params = ['page' => $options['page'] ?? 1, 'pageSize' => $options['pageSize'] ?? 50];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url, $params);
        $this->logRequest('contacts', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('message') ?? $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('message') ?? $res->json('error', 'error'))];
        return $res->collect()->toArray();
    }

    public function sendContact(string $uid, string $token, string $to, string $contactName, string $contactPhone, array $options = []): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'send-contact'], $this->baseUrl());
        $payload = ['phone' => $to, 'contactName' => $contactName, 'contactPhone' => $contactPhone];
        if (isset($options['delayMessage'])) $payload['delayMessage'] = (int) $options['delayMessage'];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($url, $payload);
        $this->logRequest('sendContact', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function communities(string $uid, string $token, array $options = []): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'communities'], $this->baseUrl());
        $params = ['page' => $options['page'] ?? 1, 'pageSize' => $options['pageSize'] ?? 10];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url, $params);
        $this->logRequest('communities', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function communitiesMetadata(string $uid, string $token, string $id): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'communities-metadata/' . $id], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url);
        $this->logRequest('communitiesMetadata', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function community(string $uid, string $token, array $data): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'communities'], $this->baseUrl());
        $payload = ['name' => $data['name'], 'description' => $data['description'] ?? null];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($url, $payload);
        $this->logRequest('community', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        $communityId = $res->json('id');
        $startTime2 = microtime(true);
        $settingsUrl = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'communities/settings'], $this->baseUrl());
        $settingsPayload = ['communityId' => $communityId, 'whoCanAddNewGroups' => 'admins'];
        $res2 = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($settingsUrl, $settingsPayload);
        $this->logRequest('community.settings', $uid, $res2->failed() || $res2->json('error') ? ['error' => $res2->json('error', 'error')] : [], $startTime2, $res2, $settingsUrl, $settingsPayload);
        return $res->json();
    }

    public function groupInvitationMetadata(string $uid, string $token, string $url): array
    {
        $startTime = microtime(true);
        $endpoint = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'group-invitation-metadata'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($endpoint, ['url' => $url]);
        $this->logRequest('groupInvitationMetadata', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('message') ?? $res->json('error', 'error')] : [], $startTime, $res, $endpoint);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('message') ?? $res->json('error', 'error'))];
        return $res->json();
    }

    public function chats(string $uid, string $token, array $options = []): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'chats'], $this->baseUrl());
        $params = [];
        if (isset($options['page'])) $params['page'] = (int) $options['page'];
        if (isset($options['pageSize'])) $params['pageSize'] = (int) $options['pageSize'];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url, $params);
        $this->logRequest('chats', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function deleteChat(string $uid, string $token, string $phone): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'modify-chat'], $this->baseUrl());
        $payload = ['phone' => $phone, 'action' => 'delete'];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->post($url, $payload);
        $this->logRequest('deleteChat', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function deleteMessage(string $uid, string $token, string $messageId, string $phone, bool $owner): array
    {
        $startTime = microtime(true);
        $base = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'messages'], $this->baseUrl());
        $query = http_build_query(['messageId' => $messageId, 'phone' => $phone]) . ($owner ? '&owner=true' : '');
        $url = $base . '?' . $query;
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(20)->delete($url);
        $this->logRequest('deleteMessage', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    protected function formatError(string $error): string
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
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'queue'], $this->baseUrl());
        $params = ['page' => $options['page'] ?? 1, 'pageSize' => $options['pageSize'] ?? 499];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url, $params);
        $this->logRequest('showQueue', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function queueCount(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'queue/count'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->get($url);
        $this->logRequest('queueCount', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['count' => $res->json('count')];
    }

    public function deleteQueueMessage(string $uid, string $token, string $messageQueueUid): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'queue/' . $messageQueueUid], $this->baseUrl());
        $res = Http::withHeaders(['accept' => 'application/json', 'client-token' => env('ZAPI_CLIENT_TOKEN', '')])->delete($url);
        $this->logRequest('deleteQueueMessage', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function clearQueue(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'queue'], $this->baseUrl());
        $res = Http::withHeaders(['accept' => 'application/json', 'client-token' => env('ZAPI_CLIENT_TOKEN', '')])->delete($url);
        $this->logRequest('clearQueue', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function createNewsletter(string $uid, string $token, string $name, string $description): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'create-newsletter'], $this->baseUrl());
        $payload = ['name' => $name, 'description' => $description];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(config("$this->configPrefix.timeout", 120))->post($url, $payload);
        $this->logRequest('createNewsletter', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function updateNewsletterName(string $uid, string $token, string $id, string $name): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'update-newsletter-name'], $this->baseUrl());
        $payload = ['id' => $id, 'name' => $name];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(config("$this->configPrefix.timeout", 120))->put($url, $payload);
        $this->logRequest('updateNewsletterName', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function updateNewsletterDescription(string $uid, string $token, string $id, string $description): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'update-newsletter-description'], $this->baseUrl());
        $payload = ['id' => $id, 'description' => $description];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(config("$this->configPrefix.timeout", 120))->put($url, $payload);
        $this->logRequest('updateNewsletterDescription', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function updateNewsletterPicture(string $uid, string $token, string $id, string $photoUrl): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'update-newsletter-picture'], $this->baseUrl());
        $payload = ['id' => $id, 'picture' => $photoUrl];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(config("$this->configPrefix.timeout", 120))->put($url, $payload);
        $this->logRequest('updateNewsletterPicture', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function newsletters(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'newsletter'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(config("$this->configPrefix.timeout", 60))->get($url);
        $this->logRequest('newsletters', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function newsletterMetadata(string $uid, string $token, string $id): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'newsletter/metadata/' . $id], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(config("$this->configPrefix.timeout", 60))->get($url);
        $this->logRequest('newsletterMetadata', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function groupInvitationLink(string $uid, string $token, string $groupId): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'group-invitation-link/' . $groupId . '-group'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(config("$this->configPrefix.timeout", 60))->get($url);
        $this->logRequest('groupInvitationLink', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function lightGroupMetadata(string $uid, string $token, string $groupId): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'light-group-metadata/' . $groupId . '-group'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(config("$this->configPrefix.timeout", 60))->get($url);
        $this->logRequest('lightGroupMetadata', $uid, $res->failed() || $res->json('error') || $res->json('success') === false ? ['error' => $res->json('error', $res->json('message', 'error'))] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error') || $res->json('success') === false) return ['error' => $this->formatError($res->json('error', $res->json('message', 'error')))];
        return $res->json();
    }

    public function groupMetadata(string $uid, string $token, string $groupId): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'group-metadata/' . $groupId . '-group'], $this->baseUrl());
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(config("$this->configPrefix.timeout", 120))->get($url);
        $this->logRequest('groupMetadata', $uid, $res->failed() || $res->json('error') || $res->json('success') === false ? ['error' => $res->json('error', $res->json('message', 'error'))] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error') || $res->json('success') === false) return ['error' => $this->formatError($res->json('error', $res->json('message', 'error')))];
        return $res->json();
    }

    public function pinMessage(string $uid, string $token, string $phone, string $messageId, int $duration): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'pin-message'], $this->baseUrl());
        $payload = ['phone' => $phone, 'messageId' => $messageId, 'time' => $duration];
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(config("$this->configPrefix.timeout", 60))->post($url, $payload);
        $this->logRequest('pinMessage', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function addContacts(string $uid, string $token, array $contacts): array
    {
        $startTime = microtime(true);
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'contacts/add'], $this->baseUrl());
        $payload = array_values($contacts);
        $res = Http::withHeaders(['Client-Token' => config("$this->configPrefix.client_token")])->timeout(config("$this->configPrefix.timeout", 120))->post($url, $payload);
        $this->logRequest('addContacts', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
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
        $clientToken = config("$this->configPrefix.client_token");
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
            $base = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, 'messages'], $this->baseUrl());
            $query = http_build_query(['messageId' => $messageId, 'phone' => $phone]) . ($owner ? '&owner=true' : '');
            $url = $base . '?' . $query;

            // Return a closure that will be executed in the pool
            return fn($pool) => $pool->as($messageId)
                ->withHeaders($headers)
                ->timeout(config("$this->configPrefix.timeout", 29))
                ->delete($url);
        });

        logger()->info("wapi-gateway.{$this->configPrefix}.delete_messages_concurrently", [
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

        logger()->info("wapi-gateway.{$this->configPrefix}.delete_messages_concurrently.completed", [
            'instance_uid' => $uid,
            'total_requests' => count($deleteRequests),
            'successful' => count(array_filter($results, fn($r) => !isset($r['error']))),
            'failed' => count(array_filter($results, fn($r) => isset($r['error'])))
        ]);

        return $results;
    }
}
