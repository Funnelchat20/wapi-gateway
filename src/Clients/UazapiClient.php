<?php

namespace Funnelchat\WapiGateway\Clients;

use Funnelchat\WapiGateway\Contracts\MessagesContract;
use Funnelchat\WapiGateway\Contracts\InstancesContract;
use Funnelchat\WapiGateway\Contracts\GroupsContract;
use Funnelchat\WapiGateway\Contracts\ContactsContract;
use Funnelchat\WapiGateway\Contracts\QueueContract;
use Funnelchat\WapiGateway\Resources\Uazapi\MessageResource;
use Funnelchat\WapiGateway\Resources\Uazapi\QrCodeResource;
use Funnelchat\WapiGateway\Resources\Uazapi\MeResource;
use Funnelchat\WapiGateway\Resources\Uazapi\LogOutResource;
use Funnelchat\WapiGateway\Resources\Uazapi\RebootResource;
use Funnelchat\WapiGateway\Resources\Uazapi\CheckPhoneResource;
use Funnelchat\WapiGateway\Resources\Uazapi\ContactResource;
use Funnelchat\WapiGateway\Resources\Uazapi\GroupsResource;
use Funnelchat\WapiGateway\Resources\Uazapi\GroupResource;
use Funnelchat\WapiGateway\Resources\Uazapi\CreateGroupResource;
use Funnelchat\WapiGateway\Traits\LogsDeviceRequests;
use Illuminate\Support\Facades\Http;

class UazapiClient implements MessagesContract, InstancesContract, GroupsContract, ContactsContract, QueueContract
{
    use LogsDeviceRequests;

    protected function getProviderName(): string
    {
        return 'uazapi';
    }
    // Status constants for compatibility with WAPI
    private const DISCONNECTED = 'disconnected';
    private const CONNECTING = 'connecting';
    private const CONNECTED = 'connected';
    private const INSTANCE_STATUSES = [self::DISCONNECTED, self::CONNECTING, self::CONNECTED];
    private const QR_CODE_RETRIEVAL_ERROR_MESSAGE = 'Error retrieving QR code or pair code.';

    public function sendText(string $uid, string $token, string $to, string $text, array $options = []): array
    {
        $startTime = microtime(true);
        $base = config('uazapi.base_url');
        $timeout = config('uazapi.timeout', 120);
        $url = $base . config('uazapi.endpoints.send_message');
        $payload = ['number' => $to, 'text' => $text];
        if (isset($options['delayMessage'])) $payload['delay'] = (int) $options['delayMessage'];
        $res = Http::withHeaders(['token' => $token])->timeout($timeout)->asJson()->post($url, $payload);
        if ($res->failed() || $res->json('error')) {
            $error = $this->formatError($res->json('message') ?? $res->json('error') ?? 'error');
            $this->logRequest('sendText', $uid, ['error' => $error, 'phone' => $to], $startTime, $res, $url, $payload);
            return ['error' => $error];
        }
        $this->logRequest('sendText', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function create(int $userId, int $deviceId): array
    {
        $startTime = microtime(true);
        $base = config('uazapi.base_url');
        $timeout = config('uazapi.timeout', 120);
        $name = 'U-' . $userId . ' D-' . $deviceId;
        $url = $base . '/instance/init';

        // 1. Create instance
        $res = Http::withHeaders(['admintoken' => config('uazapi.admin_token')])
            ->timeout($timeout)
            ->post($url, ['name' => $name]);

        $this->logRequest('create', "U-{$userId}-D-{$deviceId}", $res->failed() ? ['error' => $res->json('error', 'Failed to create instance')] : [], $startTime, $res, $url, ['name' => $name]);
        if ($res->failed()) {
            return ['error' => $res->json('error', 'Failed to create instance')];
        }

        $instanceId = $res->json('instance.id');
        $token = $res->json('instance.token');

        // 2. Configure webhooks automatically (if enabled)
        if (config('uazapi.auto_configure_webhooks', true) && $token) {
            $this->configureWebhooks($token, $userId, $deviceId);
        }

        return [
            'uid' => $instanceId,
            'token' => $token
        ];
    }

    public function status(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . config('uazapi.endpoints.status');
        $res = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 30))->get($url);
        $this->logRequest('status', $uid, $res->failed() ? ['error' => $res->json('message') ?? $res->json('error')] : [], $startTime, $res, $url);

        // Handle HTTP failures
        if ($res->failed()) {
            $errorMessage = $res->json('message') ?? $res->json('error');
            if ($errorMessage) {
                return ['error' => $this->formatError($errorMessage)];
            }
        }

        $data = $res->json();

        // Verify if response has error (even with HTTP 200)
        if (isset($data['error']) && !in_array($data['error'], self::INSTANCE_STATUSES)) {
            return ['error' => $this->formatError($data['error'])];
        }

        // Extract instance status from nested structure
        $instanceStatus = $data['instance']['status'] ?? self::DISCONNECTED;
        
        // ALWAYS add accountStatus field (WAPI compatibility)
        $accountStatus = match ($instanceStatus) {
            self::CONNECTED => 'authenticated',
            self::CONNECTING => 'got qr code',
            self::DISCONNECTED => 'got qr code',
            default => 'got qr code',
        };
        
        $qrCode = '';

        // If not connected, we need to initiate connection process to get a valid QR code
        if ($instanceStatus !== self::CONNECTED) {
            $cacheKey = "uazapi:qrcode:{$uid}";
            $connectTriggeredKey = "uazapi:connect_triggered:{$uid}";
            $connectLockKey = "uazapi:connect_lock:{$uid}";

            // Try to get from cache first
            if (function_exists('cache')) {
                $qrcode = cache()->get($cacheKey);
            }

            // If not in cache, we need to generate a new QR
            if (empty($qrcode)) {
                // Check if connect process is already ongoing or recently triggered
                // We use a longer TTL (90 seconds) to avoid interrupting an active QR scan
                $connectInProgress = function_exists('cache') ? cache()->has($connectTriggeredKey) : false;
                
                // Only trigger /instance/connect if NOT already in progress AND instance is truly disconnected
                if (!$connectInProgress && $instanceStatus === self::DISCONNECTED) {
                    // Use atomic lock to prevent race conditions from multiple concurrent requests
                    $lock = function_exists('cache') ? cache()->lock($connectLockKey, 5) : null;
                    
                    if ($lock && $lock->get()) {
                        try {
                            // STEP 1: Call /instance/connect to initiate the connection process
                            $connectUrl = config('uazapi.base_url') . config('uazapi.endpoints.connect');
                            $connectRes = Http::withHeaders(['token' => $token])
                                ->timeout(config('uazapi.timeout', 30))
                                ->withBody('{}', 'application/json')
                                ->post($connectUrl);

                            // Mark that we triggered connect (TTL: 90 seconds - enough time for user to scan)
                            // This prevents multiple /instance/connect calls while user is trying to scan
                            if (function_exists('cache')) {
                                cache()->put($connectTriggeredKey, true, now()->addSeconds(90));
                            }
                        } finally {
                            optional($lock)->release();
                        }
                    }
                }

                // STEP 2: Get the QR code from current status or from fresh /instance/status call
                // First try to use the QR from the initial status call (avoids extra API call)
                $qrcode = $data['instance']['qrcode'] ?? null;
                
                // If no QR in initial response and we're in connecting state, fetch fresh status
                if (empty($qrcode) && $instanceStatus === self::CONNECTING) {
                    $statusRes = Http::withHeaders(['token' => $token])
                        ->timeout(config('uazapi.timeout', 30))
                        ->get($url);

                    if (!$statusRes->failed()) {
                        $statusData = $statusRes->json();
                        $qrcode = $statusData['instance']['qrcode'] ?? null;
                    }
                }

                // Cache the QR code for 25 seconds (shorter than connect trigger to force refresh)
                if (!empty($qrcode) && function_exists('cache')) {
                    cache()->put($cacheKey, $qrcode, now()->addSeconds(25));
                }
            }

            if (!empty($qrcode)) {
                $qrCode = $qrcode;
            }
        } else {
            // If connected, clear all cached data
            $cacheKey = "uazapi:qrcode:{$uid}";
            $connectTriggeredKey = "uazapi:connect_triggered:{$uid}";
            $connectLockKey = "uazapi:connect_lock:{$uid}";
            if (function_exists('cache')) {
                cache()->forget($cacheKey);
                cache()->forget($connectTriggeredKey);
                cache()->forget($connectLockKey);
            }
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
        $url = config('uazapi.base_url') . config('uazapi.endpoints.qr_code');
        $res = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 30))->withBody('{}', 'application/json')->post($url);
        $this->logRequest('qrCode', $uid, $res->failed() ? ['error' => $res->json('message') ?? $res->json('error') ?? 'error'] : [], $startTime, $res, $url);
        if ($res->failed()) return ['error' => $this->formatError($res->json('message') ?? $res->json('error') ?? 'error')];
        $data = $res->json();
        return ['qrcode' => $data['instance']['qrcode'] ?? $data['qrcode'] ?? null, 'paircode' => $data['instance']['paircode'] ?? $data['paircode'] ?? null];
    }

    public function logout(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . config('uazapi.endpoints.disconnect');
        $res = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 30))->post($url);
        $this->logRequest('logout', $uid, $res->failed() ? ['error' => $res->json('message') ?? $res->json('error') ?? 'error'] : [], $startTime, $res, $url);
        if ($res->failed()) return ['error' => $this->formatError($res->json('message') ?? $res->json('error') ?? 'error')];
        return LogOutResource::make($res->json());
    }

    public function reboot(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $discUrl = config('uazapi.base_url') . config('uazapi.endpoints.disconnect');
        $disc = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 30))->post($discUrl);
        $this->logRequest('reboot', $uid, $disc->failed() ? ['error' => $disc->json('message') ?? $disc->json('error') ?? 'error', 'step' => 'disconnect'] : ['step' => 'disconnect'], $startTime, $disc, $discUrl);
        if ($disc->failed()) return ['error' => $this->formatError($disc->json('message') ?? $disc->json('error') ?? 'error')];
        $connStartTime = microtime(true);
        $connUrl = config('uazapi.base_url') . config('uazapi.endpoints.connect');
        $conn = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 30))->post($connUrl);
        $this->logRequest('reboot', $uid, $conn->failed() ? ['error' => $conn->json('message') ?? $conn->json('error') ?? 'error', 'step' => 'connect'] : ['step' => 'connect'], $connStartTime, $conn, $connUrl);
        if ($conn->failed()) return ['error' => $this->formatError($conn->json('message') ?? $conn->json('error') ?? 'error')];
        return ['status' => 'restarted'];
    }

    public function me(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . config('uazapi.endpoints.status');
        $res = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 30))->get($url);
        $this->logRequest('me', $uid, $res->failed() ? ['error' => $res->json('message') ?? $res->json('error') ?? 'error'] : [], $startTime, $res, $url);
        if ($res->failed()) return ['error' => $this->formatError($res->json('message') ?? $res->json('error') ?? 'error')];
        return MeResource::make($res->json());
    }

    public function checkPhone(string $uid, string $token, string $phone): array
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . '/contact/checkPhone/' . $phone;
        $res = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 30))->get($url);
        $this->logRequest('checkPhone', $uid, $res->failed() ? ['error' => $res->json('message') ?? $res->json('error') ?? 'error', 'phone' => $phone] : ['phone' => $phone], $startTime, $res, $url);
        if ($res->failed()) return ['error' => $this->formatError($res->json('message') ?? $res->json('error') ?? 'error')];
        return CheckPhoneResource::make($res->json());
    }

    public function subscribe(string $uid, string $token): array
    {
        return ['error' => 'Not supported'];
    }

    public function unsubscribe(string $uid, string $token): array
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . config('uazapi.endpoints.disconnect');
        $disc = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 30))->post($url);
        $this->logRequest('unsubscribe', $uid, $disc->failed() || $disc->json('error') ? ['error' => $disc->json('error') ?? $disc->json('message')] : [], $startTime, $disc, $url);
        if ($disc->failed() || $disc->json('error')) return ['error' => $this->formatError($disc->json('error') ?? $disc->json('message'))];
        return ['success' => true];
    }

    public function getParticipants(string $uid, string $token, string $phone): array
    {
        return [];
    }

    public function updateWebhookReceived(string $uid, string $token, int $userId, int $deviceId, bool $privateMessages = false): array
    {
        return [];
    }

    public function updateWebhookReceivedAndDelivery(string $uid, string $token, int $userId, int $deviceId): array
    {
        return [];
    }

    public function sendFile(string $uid, string $token, string $to, string $fileUrl, array $options = []): array
    {
        $startTime = microtime(true);
        $ext = strtolower(pathinfo($fileUrl, PATHINFO_EXTENSION));
        $type = $this->mapType($ext);
        if ($type === 'invalid') return ['error' => 'Invalid file extension'];
        $payload = ['number' => $to, 'type' => $type, 'file' => $fileUrl];
        if (isset($options['caption'])) $payload['text'] = $options['caption'];
        if (isset($options['fileName']) && $type === 'document') $payload['docName'] = $options['fileName'];
        if (isset($options['delayMessage'])) $payload['delay'] = (int) $options['delayMessage'];
        $url = config('uazapi.base_url') . config('uazapi.endpoints.send_document');
        $res = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 120))->asJson()->post($url, $payload);
        if ($res->failed() || $res->json('error')) {
            $error = $this->formatError($res->json('error'));
            $this->logRequest('sendFile', $uid, ['error' => $error, 'phone' => $to, 'file_type' => $ext], $startTime, $res, $url, $payload);
            return ['error' => $error];
        }
        $this->logRequest('sendFile', $uid, ['phone' => $to, 'file_type' => $ext], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function sendLocation(string $uid, string $token, string $to, float $lat, float $lng, array $options = []): array
    {
        $startTime = microtime(true);
        $payload = ['number' => $to, 'latitude' => $lat, 'longitude' => $lng];
        if (isset($options['name'])) $payload['name'] = $options['name'];
        if (isset($options['address'])) $payload['address'] = $options['address'];
        if (isset($options['delayMessage'])) $payload['delay'] = (int) $options['delayMessage'];
        $url = config('uazapi.base_url') . config('uazapi.endpoints.send_location');
        $res = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 120))->asJson()->post($url, $payload);
        $this->logRequest('sendLocation', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error'), 'phone' => $to] : ['phone' => $to], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error'))];
        }
        return MessageResource::make($res->json());
    }

    public function sendButtons(string $uid, string $token, string $to, string $message, array $buttons, array $options = []): array
    {
        $startTime = microtime(true);
        $choices = array_map(fn($b) => ($b['label'] ?? '') . '|' . ($b['id'] ?? ''), $buttons);
        $payload = ['number' => $to, 'type' => 'button', 'text' => $message, 'choices' => $choices];
        if (isset($options['delayMessage'])) $payload['delay'] = (int) $options['delayMessage'];
        $url = config('uazapi.base_url') . config('uazapi.endpoints.send_buttons');
        $res = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 120))->asJson()->post($url, $payload);
        $this->logRequest('sendButtons', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error'), 'phone' => $to] : ['phone' => $to], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error'))];
        }
        return $res->json();
    }

    public function sendButtonLink(string $uid, string $token, string $to, string $message, string $url, string $label, array $options = []): array
    {
        $startTime = microtime(true);
        $payload = ['number' => $to, 'type' => 'button', 'text' => $message, 'choices' => [$label . '|' . $url]];
        if (isset($options['delayMessage'])) $payload['delay'] = (int) $options['delayMessage'];
        $endpointUrl = config('uazapi.base_url') . config('uazapi.endpoints.send_buttons');
        $res = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 120))->asJson()->post($endpointUrl, $payload);
        $this->logRequest('sendButtonLink', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error'), 'phone' => $to] : ['phone' => $to], $startTime, $res, $endpointUrl, $payload);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error'))];
        }
        return $res->json();
    }

    public function sendOptionList(string $uid, string $token, string $to, string $message, string $buttonLabel, array $optionsList, array $extra = []): array
    {
        $startTime = microtime(true);
        $choices = ['[Opciones]'];
        foreach ($optionsList as $option) {
            $row = ($option['title'] ?? '') . '|' . ($option['id'] ?? '');
            if (isset($option['description'])) $row .= '|' . $option['description'];
            $choices[] = $row;
        }
        $payload = ['number' => $to, 'type' => 'list', 'text' => $message, 'choices' => $choices, 'listButton' => $buttonLabel];
        if (isset($extra['delayMessage'])) $payload['delay'] = (int) $extra['delayMessage'];
        $url = config('uazapi.base_url') . config('uazapi.endpoints.send_list');
        $res = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 120))->asJson()->post($url, $payload);
        $this->logRequest('sendOptionList', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error'), 'phone' => $to] : ['phone' => $to], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error'))];
        }
        return $res->json();
    }

    public function sendPoll(string $uid, string $token, string $to, string $message, array $pollOptions, array $options = []): array
    {
        $startTime = microtime(true);
        $payload = ['number' => $to, 'type' => 'poll', 'text' => $message, 'choices' => array_values($pollOptions)];
        if (isset($options['pollMaxOptions'])) $payload['selectableCount'] = (int) $options['pollMaxOptions'];
        if (isset($options['delayMessage'])) $payload['delay'] = (int) $options['delayMessage'];
        $url = config('uazapi.base_url') . config('uazapi.endpoints.send_poll');
        $res = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 120))->asJson()->post($url, $payload);
        $this->logRequest('sendPoll', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error'), 'phone' => $to] : ['phone' => $to], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error'))];
        }
        return $res->json();
    }

    public function sendLink(string $uid, string $token, string $to, string $message, string $linkUrl, array $options = []): array
    {
        $startTime = microtime(true);
        $payload = ['number' => $to, 'text' => $message . ' ' . $linkUrl, 'linkPreview' => true];
        if (isset($options['title'])) $payload['linkPreviewTitle'] = $options['title'];
        if (isset($options['linkDescription'])) $payload['linkPreviewDescription'] = $options['linkDescription'];
        if (isset($options['image'])) $payload['linkPreviewImage'] = $options['image'];
        if (isset($options['delayMessage'])) $payload['delay'] = (int) $options['delayMessage'];
        $url = config('uazapi.base_url') . config('uazapi.endpoints.send_link');
        $res = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 120))->asJson()->post($url, $payload);
        $this->logRequest('sendLink', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error'), 'phone' => $to] : ['phone' => $to], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error'))];
        }
        return $res->json();
    }

    public function sendEvent(string $uid, string $token, string $toGroupPhone, array $event, array $options = []): array
    {
        $text = "📅 *{$event['name']}*\n\n";
        if (!empty($event['description'])) $text .= $event['description'] . "\n\n";
        if (!empty($event['dateTime'])) $text .= '🗓️ Fecha: ' . date('d/m/Y H:i', strtotime($event['dateTime']));
        if (!empty($event['location'])) {
            $text .= "\n📍 Ubicación: " . ($event['location']['name'] ?? '');
            if (!empty($event['location']['address'])) $text .= "\n   " . $event['location']['address'];
        }
        if (!empty($event['callLinkType'])) $text .= "\n📞 Tipo: " . ($event['callLinkType'] === 'video' ? 'Videollamada' : 'Llamada de voz');
        return $this->sendText($uid, $token, $toGroupPhone, $text, $options);
    }

    public function sendTemplate(string $uid, string $token, string $to, string $name, string $languageCode, array $components): array
    {
        return ['error' => 'Not supported'];
    }

    private function mapType(string $ext): string
    {
        return [
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image',
            'webp' => 'sticker', 'svg' => 'sticker',
            'pdf' => 'document', 'doc' => 'document', 'docx' => 'document',
            'mp3' => 'audio', 'ogg' => 'audio', 'aac' => 'audio', 'm4a' => 'audio', 'opus' => 'audio', 'oga' => 'audio', 'wav' => 'audio',
            'mp4' => 'video', 'mov' => 'video', 'avi' => 'video', 'wmv' => 'video'
        ][$ext] ?? 'invalid';
    }

    private function formatError(mixed $error): string
    {
        if ($error === null) return 'Unknown error';

        if (is_array($error)) {
            $error = $error['message'] ?? $error['reason'] ?? $error['error'] ?? json_encode($error);
        } elseif (!is_string($error)) {
            $error = json_encode($error) ?: (string) $error;
        }

        return ucfirst(str_replace(['_', '-'], ' ', strtolower($error)));
    }

    public function groups(string $uid, string $token, array $options = []): array
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . config('uazapi.endpoints.groups');
        $res = Http::withHeaders(['token' => $token, 'Accept' => 'application/json'])
            ->timeout(config('uazapi.timeout', 30))
            ->get($url);
        $this->logRequest('groups', $uid, $res->failed() ? ['error' => $res->json('error') ?? $res->json('message') ?? 'Unknown error'] : [], $startTime, $res, $url);
        if ($res->failed()) {
            return ['error' => $this->formatError($res->json('error') ?? $res->json('message') ?? 'Unknown error')];
        }
        $data = $res->json();
        $groups = $data['groups'] ?? [];
        if (!is_array($groups)) $groups = [];
        return collect($groups)
            ->filter(fn($g) => isset($g['id']) || isset($g['JID']))
            ->values()
            ->toArray();
    }

    public function adGroups(string $uid, string $token, array $options = []): array
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . config('uazapi.endpoints.groups');
        $res = Http::withHeaders(['token' => $token, 'Accept' => 'application/json'])
            ->timeout(config('uazapi.timeout', 30))
            ->get($url);
        $this->logRequest('adGroups', $uid, $res->failed() ? ['error' => $res->json('error') ?? $res->json('message') ?? 'Unknown error'] : [], $startTime, $res, $url);
        if ($res->failed()) {
            return ['error' => $this->formatError($res->json('error') ?? $res->json('message') ?? 'Unknown error')];
        }
        $data = $res->json();
        $groups = $data['groups'] ?? [];
        if (!is_array($groups)) $groups = [];
        return collect($groups)
            ->filter(fn($g) => ($g['IsAnnounce'] ?? false) === true)
            ->values()
            ->toArray();
    }

    public function group(string $uid, string $token, string $id): array
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . config('uazapi.endpoints.group');
        $payload = ['groupjid' => str_contains($id, '@g.us') ? $id : ($id . '@g.us')];
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 30))
            ->asJson()
            ->post($url, $payload);
        $this->logRequest('group', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error') ?? $res->json('message')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) {
            return ['error' => $this->formatError($res->json('error') ?? $res->json('message'))];
        }
        return $res->json();
    }

    public function createGroup(string $uid, string $token, string $name, array $participants, array $options = []): array
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . config('uazapi.endpoints.create_group');
        $payload = ['name' => $name, 'participants' => array_values($participants)];
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 120))
            ->asJson()
            ->post($url, $payload);
        $this->logRequest('createGroup', $uid, $res->failed() ? ['error' => $res->json('message') ?? $res->json('error') ?? 'Group creation failed'] : [], $startTime, $res, $url, $payload);
        if ($res->failed()) {
            return ['error' => $this->formatError($res->json('message') ?? $res->json('error') ?? 'Group creation failed')];
        }
        $data = $res->json();
        $groupId = $data['group']['JID'] ?? $data['id'] ?? $data['phone'] ?? $data['groupId'] ?? null;
        if (!$groupId) return ['error' => 'group_id_missing'];
        if (!empty($options['admins'])) {
            $this->updateParticipants($token, $groupId, 'promote', $options['admins'], $uid);
        }
        if (!empty($options['photo'])) {
            Http::withHeaders(['token' => $token])
                ->timeout(config('uazapi.timeout', 60))
                ->asJson()
                ->post(config('uazapi.base_url') . '/group/updateImage', ['groupjid' => $groupId, 'image' => $options['photo']]);
        }
        return CreateGroupResource::make($data);
    }

    public function updateGroupName(string $uid, string $token, string $id, string $name): array
    {
        $startTime = microtime(true);
        $gid = str_contains($id, '@g.us') ? $id : ($id . '@g.us');
        $url = config('uazapi.base_url') . config('uazapi.endpoints.update_group_name');
        $payload = ['groupjid' => $gid, 'name' => $name];
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 60))
            ->asJson()
            ->post($url, $payload);
        $this->logRequest('updateGroupName', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error'))];
        return ['success' => true];
    }

    public function updateGroupDescription(string $uid, string $token, string $id, string $description): array
    {
        $startTime = microtime(true);
        $gid = str_contains($id, '@g.us') ? $id : ($id . '@g.us');
        $url = config('uazapi.base_url') . config('uazapi.endpoints.update_group_description');
        $payload = ['groupjid' => $gid, 'description' => $description];
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 60))
            ->asJson()
            ->post($url, $payload);
        $this->logRequest('updateGroupDescription', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error'))];
        return ['success' => true];
    }

    public function updateGroupSettings(string $uid, string $token, string $id, bool $adminOnlyMessage, bool $adminOnlySettings): array
    {
        $startTime = microtime(true);
        $gid = str_contains($id, '@g.us') ? $id : ($id . '@g.us');
        $announceUrl = config('uazapi.base_url') . config('uazapi.endpoints.update_group_announce');
        $announcePayload = ['groupjid' => $gid, 'announce' => $adminOnlyMessage];
        $announce = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 60))->asJson()
            ->post($announceUrl, $announcePayload);
        $this->logRequest('updateGroupSettings', $uid, $announce->failed() || $announce->json('error') ? ['error' => $announce->json('error'), 'step' => 'announce'] : ['step' => 'announce'], $startTime, $announce, $announceUrl, $announcePayload);
        $lockedStartTime = microtime(true);
        $lockedUrl = config('uazapi.base_url') . config('uazapi.endpoints.update_group_locked');
        $lockedPayload = ['groupjid' => $gid, 'locked' => $adminOnlySettings];
        $locked = Http::withHeaders(['token' => $token])->timeout(config('uazapi.timeout', 60))->asJson()
            ->post($lockedUrl, $lockedPayload);
        $this->logRequest('updateGroupSettings', $uid, $locked->failed() || $locked->json('error') ? ['error' => $locked->json('error'), 'step' => 'locked'] : ['step' => 'locked'], $lockedStartTime, $locked, $lockedUrl, $lockedPayload);
        if ($announce->failed() || $announce->json('error')) return ['error' => $this->formatError($announce->json('error'))];
        if ($locked->failed() || $locked->json('error')) return ['error' => $this->formatError($locked->json('error'))];
        return ['success' => true];
    }

    public function updateGroupPhoto(string $uid, string $token, string $id, string $photoUrl): array
    {
        $startTime = microtime(true);
        $gid = str_contains($id, '@g.us') ? $id : ($id . '@g.us');
        $url = config('uazapi.base_url') . config('uazapi.endpoints.update_group_photo');
        $payload = ['groupjid' => $gid, 'image' => $photoUrl];
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 60))
            ->asJson()
            ->post($url, $payload);
        $this->logRequest('updateGroupPhoto', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error'))];
        return ['success' => true];
    }

    public function addParticipants(string $uid, string $token, string $id, array $phones): array
    {
        $gid = str_contains($id, '@g.us') ? $id : ($id . '@g.us');
        $res = $this->updateParticipants($token, $gid, 'add', $phones, $uid);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error') ?? $res->json('message'))];
        return ['success' => true];
    }

    public function addAdmins(string $uid, string $token, string $id, array $phones): array
    {
        $gid = str_contains($id, '@g.us') ? $id : ($id . '@g.us');
        $res = $this->updateParticipants($token, $gid, 'promote', $phones, $uid);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error') ?? $res->json('message'))];
        return ['success' => true];
    }

    public function addCommunityAdmins(string $uid, string $token, string $id, array $phones): array
    {
        $gid = str_contains($id, '@g.us') ? $id : ($id . '@g.us');
        $res = $this->updateParticipants($token, $gid, 'promote', $phones, $uid);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error') ?? $res->json('message'))];
        return ['success' => true];
    }

    public function removeParticipants(string $uid, string $token, string $id, array $phones): array
    {
        $gid = str_contains($id, '@g.us') ? $id : ($id . '@g.us');
        $res = $this->updateParticipants($token, $gid, 'remove', $phones, $uid);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error') ?? $res->json('message'))];
        return ['success' => true];
    }

    public function removeAdmins(string $uid, string $token, string $id, array $phones): array
    {
        $gid = str_contains($id, '@g.us') ? $id : ($id . '@g.us');
        $res = $this->updateParticipants($token, $gid, 'demote', $phones, $uid);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error') ?? $res->json('message'))];
        return ['success' => true];
    }

    public function removeCommunityAdmins(string $uid, string $token, string $id, array $phones): array
    {
        $gid = str_contains($id, '@g.us') ? $id : ($id . '@g.us');
        $res = $this->updateParticipants($token, $gid, 'demote', $phones, $uid);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error') ?? $res->json('message'))];
        return ['success' => true];
    }

    public function leaveGroup(string $uid, string $token, string $id): array
    {
        $startTime = microtime(true);
        $gid = str_contains($id, '@g.us') ? $id : ($id . '@g.us');
        $url = config('uazapi.base_url') . config('uazapi.endpoints.leave_group');
        $payload = ['groupjid' => $gid];
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 60))
            ->asJson()
            ->post($url, $payload);
        $this->logRequest('leaveGroup', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error') ?? $res->json('message')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error') ?? $res->json('message'))];
        return ['success' => true];
    }

    public function communities(string $uid, string $token, array $options = []): array
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . config('uazapi.endpoints.groups');
        $res = Http::withHeaders(['token' => $token, 'Accept' => 'application/json'])
            ->timeout(config('uazapi.timeout', 30))
            ->get($url);
        $this->logRequest('communities', $uid, $res->failed() ? ['error' => $res->json('error') ?? $res->json('message') ?? 'Unknown error'] : [], $startTime, $res, $url);
        if ($res->failed()) return ['error' => $this->formatError($res->json('error') ?? $res->json('message') ?? 'Unknown error')];
        $data = $res->json();
        $groups = $data['groups'] ?? $data ?? [];
        return collect($groups)->filter(fn($g) => ($g['IsParent'] ?? false) === true)->values()->toArray();
    }

    public function communitiesMetadata(string $uid, string $token, string $id): array
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . config('uazapi.endpoints.community_metadata');
        $payload = ['groupjid' => str_contains($id, '@g.us') ? $id : ($id . '@g.us')];
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 60))
            ->asJson()
            ->post($url, $payload);
        $this->logRequest('communitiesMetadata', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error') ?? $res->json('message')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error') ?? $res->json('message'))];
        return $res->json();
    }

    public function community(string $uid, string $token, array $data): array
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . config('uazapi.endpoints.create_community');
        $payload = ['name' => $data['name']];
        if (!empty($data['description'])) $payload['description'] = $data['description'];
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 120))
            ->asJson()
            ->post($url, $payload);
        $this->logRequest('community', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error') ?? $res->json('message')] : [], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error') ?? $res->json('message'))];
        return $res->json();
    }

    public function groupInvitationMetadata(string $uid, string $token, string $url): array
    {
        $startTime = microtime(true);
        $inviteCode = basename(parse_url($url, PHP_URL_PATH));
        $url = config('uazapi.base_url') . config('uazapi.endpoints.group_invitation') . '/' . $inviteCode;
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 60))
            ->get($url);
        $this->logRequest('groupInvitationMetadata', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error') ?? $res->json('message')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error') ?? $res->json('message'))];
        return $res->json();
    }

    public function chats(string $uid, string $token, array $options = []): array
    {
        $url = config('uazapi.base_url') . config('uazapi.endpoints.chats');
        $filters = [];
        if (isset($options['pageSize'])) $filters['limit'] = (int) $options['pageSize'];
        if (isset($options['page']) && isset($options['pageSize'])) $filters['offset'] = ((int) $options['page'] - 1) * (int) $options['pageSize'];
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 60))
            ->asJson()
            ->post($url, $filters);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error') ?? $res->json('message'))];
        $chats = $res->json() ?? [];
        return is_array($chats) ? $chats : [];
    }

    public function deleteChat(string $uid, string $token, string $phone): array
    {
        $url = config('uazapi.base_url') . config('uazapi.endpoints.delete_chat');
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 60))
            ->asJson()
            ->post($url, ['number' => $phone]);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error') ?? $res->json('message'))];
        return ['success' => true];
    }

    public function deleteMessage(string $uid, string $token, string $messageId, string $phone, bool $owner): array
    {
        $url = config('uazapi.base_url') . config('uazapi.endpoints.delete_message');
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 60))
            ->asJson()
            ->post($url, ['id' => $messageId]);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error') ?? $res->json('message'))];
        return ['success' => true];
    }

    public function contact(string $uid, string $token, string $phone): array
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . config('uazapi.endpoints.contacts');
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 60))
            ->get($url);
        $this->logRequest('contact', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('message') ?? $res->json('error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('message') ?? $res->json('error'))];
        $contacts = $res->json();
        $target = collect($contacts)->first(function ($c) use ($phone) {
            $jid = $c['jid'] ?? '';
            return str_starts_with($jid, $phone . '@') || str_starts_with($jid, $phone . ':');
        });
        return $target ?: ['error' => 'Contact not found'];
    }

    public function contacts(string $uid, string $token, array $options = []): array
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . config('uazapi.endpoints.contacts');
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 60))
            ->get($url);
        $this->logRequest('contacts', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('message') ?? $res->json('error')] : [], $startTime, $res, $url);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('message') ?? $res->json('error'))];
        $data = $res->json();
        return is_array($data) ? $data : [];
    }

    public function sendContact(string $uid, string $token, string $to, string $contactName, string $contactPhone, array $options = []): array
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . config('uazapi.endpoints.send_contact');
        $payload = ['number' => $to, 'fullName' => $contactName, 'phoneNumber' => $contactPhone];
        if (isset($options['delayMessage'])) $payload['delay'] = (int) $options['delayMessage'];
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 60))
            ->asJson()
            ->post($url, $payload);
        $this->logRequest('sendContact', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error'), 'phone' => $to] : ['phone' => $to], $startTime, $res, $url, $payload);
        if ($res->failed() || $res->json('error')) return ['error' => $this->formatError($res->json('error'))];
        return $res->json();
    }

    public function createNewsletter(string $uid, string $token, string $name, string $description): array
    {
        return ['error' => 'Newsletter operations are not supported by UAZAPI provider'];
    }

    public function updateNewsletterName(string $uid, string $token, string $id, string $name): array
    {
        return ['error' => 'Newsletter operations are not supported by UAZAPI provider'];
    }

    public function updateNewsletterDescription(string $uid, string $token, string $id, string $description): array
    {
        return ['error' => 'Newsletter operations are not supported by UAZAPI provider'];
    }

    public function updateNewsletterPicture(string $uid, string $token, string $id, string $photoUrl): array
    {
        return ['error' => 'Newsletter operations are not supported by UAZAPI provider'];
    }

    public function newsletters(string $uid, string $token): array
    {
        return ['error' => 'Newsletter operations are not supported by UAZAPI provider'];
    }

    public function newsletterMetadata(string $uid, string $token, string $id): array
    {
        return ['error' => 'Newsletter operations are not supported by UAZAPI provider'];
    }

    public function groupInvitationLink(string $uid, string $token, string $groupId): array
    {
        return ['error' => 'groupInvitationLink is not supported by UAZAPI provider'];
    }

    public function lightGroupMetadata(string $uid, string $token, string $groupId): array
    {
        return $this->group($uid, $token, $groupId);
    }

    public function groupMetadata(string $uid, string $token, string $groupId): array
    {
        return $this->group($uid, $token, $groupId);
    }

    public function acceptGroupInvitation(string $uid, string $token, string $invitationUrl): array
    {
        return ['error' => 'acceptGroupInvitation is not supported by UAZAPI provider'];
    }

    public function deleteMessagesConcurrently(string $uid, string $token, array $deleteRequests): array
    {
        $results = [];
        foreach ($deleteRequests as $req) {
            $results[$req['messageId']] = $this->deleteMessage($uid, $token, $req['messageId'], $req['phone'], $req['owner'] ?? false);
        }
        return $results;
    }

    public function sendPtv(string $uid, string $token, string $to, string $videoUrl, array $options = []): array
    {
        return ['error' => 'Not supported'];
    }

    public function pinMessage(string $uid, string $token, string $phone, string $messageId, string $duration): array
    {
        return ['error' => 'pinMessage is not supported by UAZAPI provider'];
    }

    public function addContacts(string $uid, string $token, array $contacts): array
    {
        return ['error' => 'addContacts is not supported by UAZAPI provider'];
    }

    private function updateParticipants(string $token, string $groupjid, string $action, array $phones, string $uid = '')
    {
        $startTime = microtime(true);
        $url = config('uazapi.base_url') . '/group/updateParticipants';
        $payload = [
            'groupjid' => $groupjid,
            'action' => $action,
            'participants' => array_values($phones)
        ];
        $res = Http::withHeaders(['token' => $token])
            ->timeout(config('uazapi.timeout', 60))
            ->asJson()
            ->post($url, $payload);
        $this->logRequest('updateParticipants', $uid, $res->failed() || $res->json('error') ? ['error' => $res->json('error') ?? $res->json('message'), 'action' => $action] : ['action' => $action], $startTime, $res, $url, $payload);
        return $res;
    }

    public function showQueue(string $uid, string $token, array $options = []): array
    {
        return [];
    }

    public function queueCount(string $uid, string $token): array
    {
        return ['count' => 0];
    }

    public function deleteQueueMessage(string $uid, string $token, string $messageQueueUid): array
    {
        return ['success' => true];
    }

    public function clearQueue(string $uid, string $token): array
    {
        return ['success' => true];
    }

    private function configureWebhooks(string $token, int $userId, int $deviceId): void
    {
        $webhookBaseUrl = config('uazapi.webhook_base_url');
        $uazapiBaseUrl = config('uazapi.base_url');
        $timeout = config('uazapi.timeout', 120);

        if (!$webhookBaseUrl) {
            \Log::warning('UAZAPI webhook configuration skipped: WEBHOOK_BASE_URL not configured', [
                'userId' => $userId,
                'deviceId' => $deviceId,
            ]);
            return;
        }

        $webhookEvents = [
            ['event' => 'messages', 'route' => 'messages'],
            ['event' => 'messages_update', 'route' => 'messages_update'],
            ['event' => 'connection', 'route' => 'connection'],
            ['event' => 'groups', 'route' => 'messages'], // Group events go to messages route
        ];

        foreach ($webhookEvents as $config) {
            $webhookUrl = $webhookBaseUrl . '/webhooks/uazapi/' . $config['route']
                        . '?userId=' . $userId . '&deviceId=' . $deviceId;

            try {
                $response = Http::withHeaders(['token' => $token])
                    ->timeout($timeout)
                    ->post($uazapiBaseUrl . '/webhook', [
                        'action' => 'add',
                        'enabled' => true,
                        'url' => $webhookUrl,
                        'events' => [$config['event']],
                        'excludeMessages' => ['wasSentByApi'],
                    ]);

                if ($response->failed() || $response->json('error')) {
                    \Log::warning('UAZAPI webhook configuration failed', [
                        'userId' => $userId,
                        'deviceId' => $deviceId,
                        'event' => $config['event'],
                        'webhookUrl' => $webhookUrl,
                        'error' => $response->json('error', 'Unknown error'),
                        'status' => $response->status(),
                    ]);
                } else {
                    \Log::info('UAZAPI webhook configured successfully', [
                        'userId' => $userId,
                        'deviceId' => $deviceId,
                        'event' => $config['event'],
                        'webhookUrl' => $webhookUrl,
                    ]);
                }
            } catch (\Exception $e) {
                \Log::error('UAZAPI webhook configuration exception', [
                    'userId' => $userId,
                    'deviceId' => $deviceId,
                    'event' => $config['event'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
