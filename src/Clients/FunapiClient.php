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

class FunapiClient extends ZApiClient
{
    use LogsDeviceRequests;

    protected string $configPrefix = 'funapi';

    protected function getProviderName(): string
    {
        return 'funapi';
    }

    // Status constants for compatibility with WAPI
    protected const YOU_ARE_NOT_CONNECTED = 'You are not connected.';
    protected const YOU_NEED_TO_RESTORE_SESSION = 'You need to restore the session.';
    protected const YOU_ARE_ALREADY_CONNECTED = 'You are already connected.';
    protected const PENDING_SUBSCRIPTION = 'To continue sending a message, you must subscribe to this instance again';
    protected const INSTANCE_STATUSES = [self::YOU_ARE_ALREADY_CONNECTED, self::YOU_ARE_NOT_CONNECTED, self::YOU_NEED_TO_RESTORE_SESSION];
    protected const QR_CODE_RETRIEVAL_ERROR_MESSAGE = 'Error retrieving QR code.';

    // FunAPI's `/device.name` field oscillates between the real WhatsApp pushname
    // and the internal "U-{userId} D-{deviceId}" placeholder set at instance
    // creation time. `me()` retries until a stable name is returned.
    private const ME_MAX_ATTEMPTS = 5;
    private const ME_BACKOFF_MICROSECONDS = 200_000;
    private const INTERNAL_NAME_PATTERN = '/^U-\d+ D-\d+$/';

    private function buildUrl(string $uid, string $token, string $action): string
    {
        $baseUrl = rtrim(config('funapi.base_url'), '/');
        return "{$baseUrl}/instances/{$uid}/token/{$token}/{$action}";
    }

    public function sendText(string $uid, string $token, string $to, string $text, array $options = []): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'send-text');
        $payload = ['phone' => $to, 'message' => $text];
        if (isset($options['mentioned'])) $payload['mentioned'] = $options['mentioned'];
        if (isset($options['delayMessage'])) $payload['delayMessage'] = (int) $options['delayMessage'];
        if (isset($options['delayTyping'])) $payload['delayTyping'] = (int) $options['delayTyping'];

        $request = Http::withHeaders(['Client-Token' => config('funapi.client_token')])
            ->timeout(config('funapi.timeout', 120));

        // Apply retry logic if enabled in options
        if ($options['retry'] ?? false) {
            $request = $request->retry(
                config('funapi.max_attempts', 2),
                config('funapi.retry_delay', 500),
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
        $webhookBaseUrl = config('funapi.webhook_base_url');
        $webhookStatusBaseUrl = config('funapi.webhook_status_base_url') ?: $webhookBaseUrl;

        $payload = [
            'name' => $name,
            'sessionName' => 'Funnelchat',
        ];

        // Only configure webhooks if WEBHOOK_BASE_URL is set.
        // FunAPI shares the Z-API-compatible webhook receiver convention with
        // Z-API and Z-API Lite — all three "primo" providers POST to
        // /webhooks/zapi/* and the host app handles them with a single
        // controller. Do NOT use /webhooks/funapi/* here — those routes do not
        // exist in the standard wapi host.
        if ($webhookBaseUrl) {
            $payload['receivedCallbackUrl'] = $webhookBaseUrl . '/webhooks/zapi/received?userId=' . $userId . '&deviceId=' . $deviceId;
            $payload['receivedAndDeliveryCallbackUrl'] = $webhookBaseUrl . '/webhooks/zapi/received-and-delivery?userId=' . $userId . '&deviceId=' . $deviceId;
            $payload['disconnectedCallbackUrl'] = $webhookBaseUrl . '/webhooks/zapi/disconnected?userId=' . $userId . '&deviceId=' . $deviceId;
            $payload['connectedCallbackUrl'] = $webhookBaseUrl . '/webhooks/zapi/connected?userId=' . $userId . '&deviceId=' . $deviceId;
            $payload['messageStatusCallbackUrl'] = $webhookStatusBaseUrl . '/webhooks/zapi/message-status?userId=' . $userId . '&deviceId=' . $deviceId;
            $payload['blockCallbackUrl'] = $webhookBaseUrl . '/webhooks/zapi/block?userId=' . $userId . '&deviceId=' . $deviceId;
        }

        $url = config('funapi.on_demand_url');
        $res = Http::withToken(config('funapi.token'))->post($url, $payload);
        $this->logRequest('create', "U-{$userId}-D-{$deviceId}", $res->failed() ? ['error' => $res->json('error', 'Failed to create instance')] : [], $startTime, $res, $url, $payload);

        if ($res->failed()) {
            return ['error' => $res->json('error', 'Failed to create instance')];
        }

        $uid = $res->json('id');
        $token = $res->json('token');

        $subscribeResult = $this->subscribe($uid, $token);
        if (isset($subscribeResult['error'])) {
            $this->logRequest('create.subscribe', "U-{$userId}-D-{$deviceId}", ['error' => $subscribeResult['error']], $startTime, $res, $url, $payload);
            return ['error' => 'Instance created but subscription activation failed: ' . $subscribeResult['error']];
        }

        return ['uid' => $uid, 'token' => $token];
    }

    public function status(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'status');
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url);
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

        // `smartphoneConnected` is the only reliable authentication signal.
        // FunAPI's `connected` and `error` fields oscillate for several seconds
        // after instance creation (e.g. returning `connected:true, error:""`
        // transiently while the session is still booting), so they cannot be
        // trusted. Only `smartphoneConnected: true` means the user scanned the
        // QR and the WhatsApp session is live.
        if (($data['smartphoneConnected'] ?? false) === true) {
            return [
                'accountStatus' => 'authenticated',
                'qrCode' => '',
            ];
        }

        // Not authenticated → try to fetch the QR. FunAPI's qr-code/image
        // hiccups with 500 "internal error" in roughly half of all calls, but
        // empirically at least 1 out of every 3-4 rapid retries succeeds. Retry
        // a few times with a short backoff so the frontend gets a stable QR
        // instead of flickering between empty and full responses. Fall back to
        // empty qrCode (still 'got qr code' state) if all retries fail — the
        // frontend will try again on its next poll.
        $qrImage = $this->fetchFunApiQrWithRetries($uid, $token);

        return [
            'accountStatus' => 'got qr code',
            'qrCode' => $qrImage,
        ];
    }

    private function fetchFunApiQrWithRetries(string $uid, string $token): string
    {
        $qrUrl = $this->buildUrl($uid, $token, 'qr-code/image');
        $headers = ['Client-Token' => config('funapi.client_token')];
        $maxAttempts = 3;
        $backoffMicroseconds = 200_000;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $qrRes = Http::withHeaders($headers)->get($qrUrl);

            if (!$qrRes->failed() && !$qrRes->json('error')) {
                $qrImage = $qrRes->json('image') ?? $qrRes->json('value') ?? '';
                if ($qrImage !== '') {
                    return $qrImage;
                }
            }

            if ($attempt < $maxAttempts) {
                usleep($backoffMicroseconds);
            }
        }

        return '';
    }

    public function qrCode(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'qr-code/image');
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url);
        $this->logRequest('qrCode', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return QrCodeResource::make($res->json());
    }

    public function logout(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'disconnect');
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url);
        $this->logRequest('logout', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return LogOutResource::make($res->json());
    }

    public function reboot(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'restart');
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url);
        $this->logRequest('reboot', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return RebootResource::make($res->json());
    }

    public function me(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'device');
        $headers = ['Client-Token' => config('funapi.client_token')];

        $lastRes = null;
        $lastData = null;

        for ($attempt = 1; $attempt <= self::ME_MAX_ATTEMPTS; $attempt++) {
            $res = Http::withHeaders($headers)->get($url);
            $lastRes = $res;

            if ($res->failed() || $res->json('error')) {
                if ($attempt < self::ME_MAX_ATTEMPTS) {
                    usleep(self::ME_BACKOFF_MICROSECONDS);
                }
                continue;
            }

            $data = $res->json();
            $lastData = $data;
            $name = $data['name'] ?? null;

            // Found a stable name (real pushname) → return immediately.
            if ($name !== null && $name !== '' && !preg_match(self::INTERNAL_NAME_PATTERN, $name)) {
                $this->logRequest('me', $uid, ['attempts' => $attempt], $startTime, $res, $url);
                return MeResource::make($data);
            }

            if ($attempt < self::ME_MAX_ATTEMPTS) {
                usleep(self::ME_BACKOFF_MICROSECONDS);
            }
        }

        $this->logRequest('me', $uid, $lastRes && $lastRes->failed() ? ['error' => $lastRes->json('error', 'error')] : ['exhausted' => true], $startTime, $lastRes, $url);

        // All attempts failed → propagate error.
        if ($lastData === null) {
            return ['error' => $this->formatError($lastRes?->json('error', 'error') ?? 'error')];
        }

        // All attempts returned the internal placeholder → return MeResource shape
        // with name explicitly null so callers do not persist the placeholder as alias.
        $result = MeResource::make($lastData);
        $result['name'] = null;
        return $result;
    }

    public function checkPhone(string $uid, string $token, string $phone): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'phone-exists/' . $phone);
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])
            ->timeout(config('funapi.timeout', 29))
            ->get($url);

        if ($res->failed() || $res->json('error')) {
            $error = $this->formatError($res->json('error', 'error'));
            $this->logRequest('checkPhone', $uid, ['error' => $error, 'phone' => $phone], $startTime, $res, $url, []);
            return ['error' => $error];
        }

        $this->logRequest('checkPhone', $uid, ['phone' => $phone], $startTime, $res, $url, []);
        return CheckPhoneResource::make($res->json());
    }

    public function subscribe(string $uid, string $token): array
    {
        $url = str_replace(['UID', 'TOKEN'], [$uid, $token], config('funapi.subscription_url'));
        $res = Http::withToken(config('funapi.token'))->post($url);
        if ($res->failed() || $res->json('error')) return ['error' => $res->json('error') ?? 'subscribe_failed'];
        return ['paidTill' => date('Y-m-d H:i:s', ($res->json('due') ?? 0) / 1000)];
    }

    public function unsubscribe(string $uid, string $token): array
    {
        $disconnectUrl = $this->buildUrl($uid, $token, 'disconnect');
        $disc = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($disconnectUrl);
        if ($disc->failed() || $disc->json('error')) return ['error' => $disc->json('error') ?? 'disconnect_failed'];
        $url = str_replace(['UID', 'TOKEN'], [$uid, $token], config('funapi.unsubscription_url'));
        $res = Http::withToken(config('funapi.token'))->post($url);
        if ($res->failed() || $res->json('error')) return ['error' => $res->json('error') ?? 'unsubscribe_failed'];
        return ['paidTill' => date('Y-m-d H:i:s', ($res->json('due') ?? 0) / 1000)];
    }

    public function updateWebhookReceived(string $uid, string $token, int $userId, int $deviceId, bool $privateMessages = false): array
    {
        $url = $this->buildUrl($uid, $token, 'update-webhook-received');
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])
            ->put($url, ['userId' => $userId, 'deviceId' => $deviceId, 'privateMessages' => $privateMessages]);
        if ($res->failed() || $res->json('error')) return ['error' => $res->json('error', 'error')];
        return $res->json() ?? [];
    }

    public function updateWebhookReceivedAndDelivery(string $uid, string $token, int $userId, int $deviceId): array
    {
        $url = $this->buildUrl($uid, $token, 'update-webhook-received-and-delivery');
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])
            ->put($url, ['userId' => $userId, 'deviceId' => $deviceId]);
        if ($res->failed() || $res->json('error')) return ['error' => $res->json('error', 'error')];
        return $res->json() ?? [];
    }

    public function getParticipants(string $uid, string $token, string $phone): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'light-group-metadata/' . $phone);
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url);
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
        // Funapi uses endpoints WITHOUT file type suffix: /send-image, /send-document (not /send-document/pdf)
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

        $url = $this->buildUrl($uid, $token, $action);

        $request = Http::withHeaders(['Client-Token' => config('funapi.client_token')])
            ->timeout(config('funapi.timeout', 120));

        // Apply retry logic if enabled in options
        if ($options['retry'] ?? false) {
            $request = $request->retry(
                config('funapi.max_attempts', 2),
                config('funapi.retry_delay', 500),
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
        $url = $this->buildUrl($uid, $token, 'send-location');
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->timeout(120)->post($url, $params);
        $this->logRequest('sendLocation', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $params);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error', 'error'))];
        }
        return MessageResource::make($res->json());
    }

    public function sendButtons(string $uid, string $token, string $to, string $message, array $buttons, array $options = []): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'send-button-list');
        // FunApi's send-button-list handler expects `buttons` at the top level
        // with `{id, title}` items — NOT the Z-API-compatible `buttonList.buttons[].label` shape.
        // The monolith passes buttons as `[{id, label}]`, so translate `label` → `title` here.
        $payload = [
            'phone' => $to,
            'message' => $message,
            'buttons' => array_map(
                fn ($btn) => [
                    'id' => (string) ($btn['id'] ?? ''),
                    'title' => (string) ($btn['title'] ?? $btn['label'] ?? ''),
                ],
                $buttons
            ),
        ];
        if (isset($options['mentioned'])) $payload['mentioned'] = $options['mentioned'];
        if (isset($options['delayMessage'])) $payload['delayMessage'] = (int) $options['delayMessage'];

        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])
            ->timeout(config('funapi.timeout', 29))
            ->post($url, $payload);

        if ($res->failed() || $res->json('error')) {
            $error = $this->formatError($res->json('error', 'error'));
            $this->logRequest('sendButtons', $uid, ['error' => $error, 'phone' => $to], $startTime, $res, $url, $payload);
            return ['error' => $error];
        }

        $this->logRequest('sendButtons', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function sendButtonLink(string $uid, string $token, string $to, string $message, string $url, string $label, array $options = []): array
    {
        $startTime = microtime(true);
        $endpoint = $this->buildUrl($uid, $token, 'send-button-actions');
        $payload = [
            'phone' => $to,
            'message' => $message,
            'buttonActions' => [
                ['type' => 'URL', 'url' => $url, 'label' => $label]
            ],
        ];
        if (isset($options['mentioned'])) $payload['mentioned'] = $options['mentioned'];
        if (isset($options['delayMessage'])) $payload['delayMessage'] = (int) $options['delayMessage'];

        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])
            ->timeout(config('funapi.timeout', 29))
            ->post($endpoint, $payload);

        if ($res->failed() || $res->json('error')) {
            $error = $this->formatError($res->json('error', 'error'));
            $this->logRequest('sendButtonLink', $uid, ['error' => $error, 'phone' => $to], $startTime, $res, $endpoint, $payload);
            return ['error' => $error];
        }

        $this->logRequest('sendButtonLink', $uid, ['phone' => $to], $startTime, $res, $endpoint, $payload);
        return MessageResource::make($res->json());
    }

    public function sendOptionList(string $uid, string $token, string $to, string $message, string $buttonLabel, array $optionsList, array $extra = []): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'send-option-list');
        // FunApi's send-option-list handler expects `sections[].rows[]` top-level with `buttonLabel`
        // alongside — NOT the Z-API-compatible `optionList.options[]` flat shape.
        // Wrap the monolith's flat options list into a single section.
        $payload = [
            'phone' => $to,
            'message' => $message,
            'buttonLabel' => $buttonLabel,
            'sections' => [
                [
                    'title' => '',
                    'rows' => array_map(
                        fn ($opt) => [
                            'id' => (string) ($opt['id'] ?? ''),
                            'title' => (string) ($opt['title'] ?? $opt['label'] ?? ''),
                            'description' => (string) ($opt['description'] ?? ''),
                        ],
                        $optionsList
                    ),
                ],
            ],
        ];
        if (isset($extra['delayMessage'])) $payload['delayMessage'] = (int) $extra['delayMessage'];

        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])
            ->timeout(config('funapi.timeout', 29))
            ->post($url, $payload);

        if ($res->failed() || $res->json('error')) {
            $error = $this->formatError($res->json('error', 'error'));
            $this->logRequest('sendOptionList', $uid, ['error' => $error, 'phone' => $to], $startTime, $res, $url, $payload);
            return ['error' => $error];
        }

        $this->logRequest('sendOptionList', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function sendPoll(string $uid, string $token, string $to, string $message, array $pollOptions, array $options = []): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'send-poll');
        $payload = [
            'phone' => $to,
            'message' => $message,
            'poll' => $pollOptions,
        ];
        if (isset($options['pollMaxOptions'])) $payload['pollMaxOptions'] = (int) $options['pollMaxOptions'];
        if (isset($options['mentioned'])) $payload['mentioned'] = $options['mentioned'];
        if (isset($options['delayMessage'])) $payload['delayMessage'] = (int) $options['delayMessage'];

        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])
            ->timeout(config('funapi.timeout', 29))
            ->post($url, $payload);

        if ($res->failed() || $res->json('error')) {
            $error = $this->formatError($res->json('error', 'error'));
            $this->logRequest('sendPoll', $uid, ['error' => $error, 'phone' => $to], $startTime, $res, $url, $payload);
            return ['error' => $error];
        }

        $this->logRequest('sendPoll', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function sendLink(string $uid, string $token, string $to, string $message, string $linkUrl, array $options = []): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'send-link');
        $payload = [
            'phone' => $to,
            'message' => $message,
            'linkUrl' => $linkUrl,
        ];
        if (isset($options['title'])) $payload['title'] = $options['title'];
        if (isset($options['linkDescription'])) $payload['linkDescription'] = $options['linkDescription'];
        if (isset($options['image'])) $payload['image'] = $options['image'];
        if (isset($options['mentioned'])) $payload['mentioned'] = $options['mentioned'];
        if (isset($options['delayMessage'])) $payload['delayMessage'] = (int) $options['delayMessage'];
        if (isset($options['delayTyping'])) $payload['delayTyping'] = (int) $options['delayTyping'];

        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])
            ->timeout(config('funapi.timeout', 29))
            ->post($url, $payload);

        if ($res->failed() || $res->json('error')) {
            $error = $this->formatError($res->json('error', 'error'));
            $this->logRequest('sendLink', $uid, ['error' => $error, 'phone' => $to], $startTime, $res, $url, $payload);
            return ['error' => $error];
        }

        $this->logRequest('sendLink', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function sendEvent(string $uid, string $token, string $toGroupPhone, array $event, array $options = []): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'send-event');
        $payload = [
            'phone' => $toGroupPhone,
            'event' => $event,
        ];

        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])
            ->timeout(config('funapi.timeout', 29))
            ->post($url, $payload);

        if ($res->failed() || $res->json('error')) {
            $error = $this->formatError($res->json('error', 'error'));
            $this->logRequest('sendEvent', $uid, ['error' => $error, 'phone' => $toGroupPhone], $startTime, $res, $url, $payload);
            return ['error' => $error];
        }

        $this->logRequest('sendEvent', $uid, ['phone' => $toGroupPhone], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function sendTemplate(string $uid, string $token, string $to, string $name, string $languageCode, array $components): array
    {
        // Templates are a WhatsApp Cloud API feature, not available on whatsmeow-based providers
        return ['error' => 'sendTemplate() is not supported by Funapi provider. Use WhatsApp Cloud API for templates.'];
    }

    public function createNewsletter(string $uid, string $token, string $name, string $description): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'create-newsletter');
        $payload = ['name' => $name, 'description' => $description];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->timeout(config('funapi.timeout', 120))->post($url, $payload);
        $this->logRequest('createNewsletter', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function updateNewsletterName(string $uid, string $token, string $id, string $name): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'update-newsletter-name');
        $payload = ['id' => $id, 'name' => $name];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->timeout(config('funapi.timeout', 120))->put($url, $payload);
        $this->logRequest('updateNewsletterName', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function updateNewsletterDescription(string $uid, string $token, string $id, string $description): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'update-newsletter-description');
        $payload = ['id' => $id, 'description' => $description];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->timeout(config('funapi.timeout', 120))->put($url, $payload);
        $this->logRequest('updateNewsletterDescription', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function updateNewsletterPicture(string $uid, string $token, string $id, string $photoUrl): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'update-newsletter-picture');
        $payload = ['id' => $id, 'picture' => $photoUrl];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->timeout(config('funapi.timeout', 120))->put($url, $payload);
        $this->logRequest('updateNewsletterPicture', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function newsletters(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'newsletter');
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->timeout(config('funapi.timeout', 60))->get($url);
        $this->logRequest('newsletters', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function newsletterMetadata(string $uid, string $token, string $id): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'newsletter/metadata/' . $id);
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->timeout(config('funapi.timeout', 60))->get($url);
        $this->logRequest('newsletterMetadata', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function groupInvitationLink(string $uid, string $token, string $groupId): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'group-invitation-link/' . $groupId . '-group');
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->timeout(config('funapi.timeout', 60))->get($url);
        $this->logRequest('groupInvitationLink', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function lightGroupMetadata(string $uid, string $token, string $groupId): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'light-group-metadata/' . $groupId . '-group');
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->timeout(config('funapi.timeout', 60))->get($url);
        $this->logRequest('lightGroupMetadata', $uid, $res->failed() || $res->json('error') || $res->json('success') === false ? ['error' => $res->json('error', $res->json('message', 'error'))] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error') || $res->json('success') === false) return ['error' => $this->formatError($res->json('error', $res->json('message', 'error')))];
        return $res->json();
    }

    public function groupMetadata(string $uid, string $token, string $groupId): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'group-metadata/' . $groupId . '-group');
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->timeout(config('funapi.timeout', 120))->get($url);
        $this->logRequest('groupMetadata', $uid, $res->failed() || $res->json('error') || $res->json('success') === false ? ['error' => $res->json('error', $res->json('message', 'error'))] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error') || $res->json('success') === false) return ['error' => $this->formatError($res->json('error', $res->json('message', 'error')))];
        return $res->json();
    }

    public function acceptGroupInvitation(string $uid, string $token, string $invitationUrl): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'accept-invite-group');
        $url .= '?' . http_build_query(['url' => $invitationUrl]);
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url);
        $this->logRequest('acceptGroupInvitation', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function pinMessage(string $uid, string $token, string $phone, string $messageId, string $duration): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'pin-message');
        $payload = ['phone' => $phone, 'messageId' => $messageId, 'messageAction' => 'pin', 'pinMessageDuration' => $duration];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->timeout(config('funapi.timeout', 60))->post($url, $payload);
        $this->logRequest('pinMessage', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function addContacts(string $uid, string $token, array $contacts): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'contacts/add');
        $payload = array_values($contacts);
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->timeout(config('funapi.timeout', 120))->post($url, $payload);
        $this->logRequest('addContacts', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
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
        $url = $this->buildUrl($uid, $token, 'groups');
        $params = ['page' => $options['page'] ?? 1, 'pageSize' => $options['pageSize'] ?? 299];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url, $params);
        $this->logRequest('groups', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        $filtered = collect($res->collect())->filter(fn($g) => array_key_exists('communityId', $g) && empty($g['communityId']))->unique('phone')->values();
        return GroupsResource::collection($filtered->toArray());
    }

    public function adGroups(string $uid, string $token, array $options = []): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'groups');
        $params = ['page' => $options['page'] ?? 1, 'pageSize' => $options['pageSize'] ?? 999];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url, $params);
        $this->logRequest('adGroups', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        $filtered = collect($res->collect())->filter(fn($g) => array_key_exists('communityId', $g) && !empty($g['communityId']))->values();
        return $filtered->toArray();
    }

    public function group(string $uid, string $token, string $id): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'group-metadata/' . $id . '-group');
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url);
        $this->logRequest('group', $uid, $res->failed() || $res->json('error') || $res->json('success') === false ? ['error' => $res->json('error', $res->json('message', 'error'))] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error') || $res->json('success') === false) return ['error' => $this->formatError($res->json('error', $res->json('message', 'error')))];
        $image = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($this->buildUrl($uid, $token, 'chats/' . $id . '-group'))->json('profileThumbnail', '');
        $data = $res->json();
        $data['image'] = $image;
        return GroupResource::make($data);
    }

    public function createGroup(string $uid, string $token, string $name, array $participants, array $options = []): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'create-group');
        $payload = ['groupName' => $name, 'phones' => $participants, 'autoInvite' => true];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($url, $payload);
        $this->logRequest('createGroup', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        $data = $res->json();
        if (!isset($data['phone'])) return ['error' => 'group_phone_missing'];
        if (!empty($options['admins'])) {
            Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($this->buildUrl($uid, $token, 'add-admin'), ['groupId' => $data['phone'], 'phones' => $options['admins']]);
        }
        if (!empty($options['photo'])) {
            Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($this->buildUrl($uid, $token, 'update-group-photo'), ['groupId' => $data['phone'], 'groupPhoto' => $options['photo']]);
        }
        return CreateGroupResource::make($data);
    }

    public function updateGroupName(string $uid, string $token, string $id, string $name): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'update-group-name');
        $payload = ['groupId' => $id, 'groupName' => $name];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($url, $payload);
        $this->logRequest('updateGroupName', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function updateGroupDescription(string $uid, string $token, string $id, string $description): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'update-group-description');
        $payload = ['groupId' => $id . '-group', 'groupDescription' => $description];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($url, $payload);
        $this->logRequest('updateGroupDescription', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function updateGroupSettings(string $uid, string $token, string $id, bool $adminOnlyMessage, bool $adminOnlySettings): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'update-group-settings');
        $payload = ['phone' => $id . '-group', 'adminOnlyMessage' => $adminOnlyMessage, 'adminOnlySettings' => $adminOnlySettings];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($url, $payload);
        $this->logRequest('updateGroupSettings', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function updateGroupPhoto(string $uid, string $token, string $id, string $photoUrl): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'update-group-photo');
        $payload = ['groupId' => $id, 'groupPhoto' => $photoUrl];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($url, $payload);
        $this->logRequest('updateGroupPhoto', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function addParticipants(string $uid, string $token, string $id, array $phones): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'add-participant');
        $payload = ['autoInvite' => true, 'groupId' => $id, 'phones' => $phones];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($url, $payload);
        $this->logRequest('addParticipants', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function addAdmins(string $uid, string $token, string $id, array $phones): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'add-admin');
        $payload = ['groupId' => $id, 'phones' => $phones];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($url, $payload);
        $this->logRequest('addAdmins', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function addCommunityAdmins(string $uid, string $token, string $id, array $phones): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'add-admin');
        $payload = ['communityId' => $id, 'phones' => $phones];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($url, $payload);
        $this->logRequest('addCommunityAdmins', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function removeParticipants(string $uid, string $token, string $id, array $phones): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'remove-participant');
        $payload = ['groupId' => $id, 'phones' => $phones];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($url, $payload);
        $this->logRequest('removeParticipants', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function removeAdmins(string $uid, string $token, string $id, array $phones): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'remove-admin');
        $payload = ['groupId' => $id, 'phones' => $phones];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($url, $payload);
        $this->logRequest('removeAdmins', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function removeCommunityAdmins(string $uid, string $token, string $id, array $phones): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'remove-admin');
        $payload = ['communityId' => $id, 'phones' => $phones];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($url, $payload);
        $this->logRequest('removeCommunityAdmins', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function leaveGroup(string $uid, string $token, string $id): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'leave-group');
        $payload = ['groupId' => $id . '-group'];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($url, $payload);
        $this->logRequest('leaveGroup', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function contact(string $uid, string $token, string $phone): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'contacts/' . $phone);
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url);
        $this->logRequest('contact', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('message') ?? $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('message') ?? $res->json('error', 'error'))];
        return ContactResource::make($res->json());
    }

    public function contacts(string $uid, string $token, array $options = []): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'contacts');
        $params = ['page' => $options['page'] ?? 1, 'pageSize' => $options['pageSize'] ?? 50];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url, $params);
        $this->logRequest('contacts', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('message') ?? $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('message') ?? $res->json('error', 'error'))];
        return $res->collect()->toArray();
    }

    public function sendContact(string $uid, string $token, string $to, string $contactName, string $contactPhone, array $options = []): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'send-contact');
        $payload = ['phone' => $to, 'contactName' => $contactName, 'contactPhone' => $contactPhone];
        if (isset($options['delayMessage'])) $payload['delayMessage'] = (int) $options['delayMessage'];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($url, $payload);
        $this->logRequest('sendContact', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function communities(string $uid, string $token, array $options = []): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'communities');
        $params = ['page' => $options['page'] ?? 1, 'pageSize' => $options['pageSize'] ?? 10];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url, $params);
        $this->logRequest('communities', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function communitiesMetadata(string $uid, string $token, string $id): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'communities-metadata/' . $id);
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url);
        $this->logRequest('communitiesMetadata', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function community(string $uid, string $token, array $data): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'communities');
        $payload = ['name' => $data['name'], 'description' => $data['description'] ?? null];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($url, $payload);
        $this->logRequest('community', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        $communityId = $res->json('id');
        $startTime2 = microtime(true);
        $settingsUrl = $this->buildUrl($uid, $token, 'communities/settings');
        $settingsPayload = ['communityId' => $communityId, 'whoCanAddNewGroups' => 'admins'];
        $settingsRes = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($settingsUrl, $settingsPayload);
        $this->logRequest('community.settings', $uid, $settingsRes->failed() || $settingsRes->json('error') ? ['error' => $settingsRes->json('error', 'error')] : [], $startTime2, $settingsRes, $settingsUrl, $settingsPayload);
        return $res->json();
    }

    public function groupInvitationMetadata(string $uid, string $token, string $url): array
    {
        $startTime = microtime(true);
        $endpoint = $this->buildUrl($uid, $token, 'group-invitation-metadata');
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($endpoint, ['url' => $url]);
        $this->logRequest('groupInvitationMetadata', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('message') ?? $res->json('error', 'error')] : [], $startTime, $res, $endpoint);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('message') ?? $res->json('error', 'error'))];
        return $res->json();
    }

    public function chats(string $uid, string $token, array $options = []): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'chats');
        $params = [];
        if (isset($options['page'])) $params['page'] = (int) $options['page'];
        if (isset($options['pageSize'])) $params['pageSize'] = (int) $options['pageSize'];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url, $params);
        $this->logRequest('chats', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function deleteChat(string $uid, string $token, string $phone): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'modify-chat');
        $payload = ['phone' => $phone, 'action' => 'delete'];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->post($url, $payload);
        $this->logRequest('deleteChat', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function deleteMessage(string $uid, string $token, string $messageId, string $phone, bool $owner): array
    {
        $startTime = microtime(true);
        $base = $this->buildUrl($uid, $token, 'messages');
        $query = http_build_query(['messageId' => $messageId, 'phone' => $phone]) . ($owner ? '&owner=true' : '');
        $url = $base . '?' . $query;
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->timeout(20)->delete($url);
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
        $url = $this->buildUrl($uid, $token, 'queue');
        $params = ['page' => $options['page'] ?? 1, 'pageSize' => $options['pageSize'] ?? 499];
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url, $params);
        $this->logRequest('showQueue', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return $res->json();
    }

    public function queueCount(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'queue/count');
        $res = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->get($url);
        $this->logRequest('queueCount', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['count' => $res->json('count')];
    }

    public function deleteQueueMessage(string $uid, string $token, string $messageQueueUid): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'queue/' . $messageQueueUid);
        $res = Http::withHeaders(['accept' => 'application/json', 'client-token' => config('funapi.client_token')])->delete($url);
        $this->logRequest('deleteQueueMessage', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error', 'error'))];
        return ['success' => true];
    }

    public function clearQueue(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = $this->buildUrl($uid, $token, 'queue');
        $res = Http::withHeaders(['accept' => 'application/json', 'client-token' => config('funapi.client_token')])->delete($url);
        $this->logRequest('clearQueue', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error', 'error')] : [], $startTime, $res, $url);
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
        $clientToken = config('funapi.client_token');
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
            $base = $this->buildUrl($uid, $token, 'messages');
            $query = http_build_query(['messageId' => $messageId, 'phone' => $phone]) . ($owner ? '&owner=true' : '');
            $url = $base . '?' . $query;

            // Return a closure that will be executed in the pool
            return fn($pool) => $pool->as($messageId)
                ->withHeaders($headers)
                ->timeout(config('funapi.timeout', 29))
                ->delete($url);
        });

        logger()->info('wapi-gateway.funapi.delete_messages_concurrently', [
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

        logger()->info('wapi-gateway.funapi.delete_messages_concurrently.completed', [
            'instance_uid' => $uid,
            'total_requests' => count($deleteRequests),
            'successful' => count(array_filter($results, fn($r) => !isset($r['error']))),
            'failed' => count(array_filter($results, fn($r) => isset($r['error'])))
        ]);

        return $results;
    }
}
