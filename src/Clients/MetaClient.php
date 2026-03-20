<?php

namespace Funnelchat\WapiGateway\Clients;

use Funnelchat\WapiGateway\Contracts\MessagesContract;
use Funnelchat\WapiGateway\Contracts\InstancesContract;
use Funnelchat\WapiGateway\Contracts\ContactsContract;
use Funnelchat\WapiGateway\Contracts\TemplatesContract;
use Funnelchat\WapiGateway\Helpers\WhatsAppCloudHelper;
use Funnelchat\WapiGateway\Jobs\StoreDeviceLogJob;
use Funnelchat\WapiGateway\Resources\Meta\MessageResource;
use Funnelchat\WapiGateway\Traits\LogsDeviceRequests;
use Illuminate\Support\Facades\Http;

class MetaClient implements MessagesContract, InstancesContract, ContactsContract, TemplatesContract
{
    use LogsDeviceRequests;

    protected function getProviderName(): string
    {
        return 'meta';
    }
    private string $graph = 'https://graph.facebook.com/v20.0/';

    public function sendText(string $uid, string $token, string $to, string $text, array $options = []): array
    {
        $startTime = microtime(true);
        $url = $this->graph . $uid . '/messages';
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text],
        ];
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendText', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $url, $payload);
            return ['error' => $res->json('error', 'Failed to send')];
        }
        $this->logRequest('sendText', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function create(int $userId, int $deviceId): array
    {
        return ['error' => 'Not supported'];
    }
    public function status(string $uid, string $token): array { return ['error' => 'Not supported']; }
    public function qrCode(string $uid, string $token): array { return ['error' => 'Not supported']; }
    public function logout(string $uid, string $token): array { return ['error' => 'Not supported']; }
    public function reboot(string $uid, string $token): array { return ['error' => 'Not supported']; }
    public function me(string $uid, string $token): array { return ['error' => 'Not supported']; }
    public function checkPhone(string $uid, string $token, string $phone): array { return ['error' => 'Not supported']; }
    public function subscribe(string $uid, string $token): array { return ['error' => 'Not supported']; }
    public function unsubscribe(string $uid, string $token): array { return ['error' => 'Not supported']; }
    public function getParticipants(string $uid, string $token, string $phone): array { return []; }
    public function updateWebhookReceived(string $uid, string $token, int $userId, int $deviceId, bool $privateMessages = false): array { return []; }
    public function updateWebhookReceivedAndDelivery(string $uid, string $token, int $userId, int $deviceId): array { return []; }

    public function sendFile(string $uid, string $token, string $to, string $fileUrl, array $options = []): array
    {
        $startTime = microtime(true);
        $ext = strtolower(pathinfo($fileUrl, PATHINFO_EXTENSION));
        $type = $this->mapType($ext);
        if ($type === 'invalid') return ['error' => 'Invalid file extension'];
        $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => $type, $type => ['link' => $fileUrl]];
        if (isset($options['fileName']) && $type === 'document') $payload[$type]['filename'] = $options['fileName'];
        if (isset($options['caption']) && in_array($type, ['image', 'video', 'document'])) $payload[$type]['caption'] = $options['caption'];
        $url = $this->graph . $uid . '/messages';
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendFile', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to, 'file_type' => $ext], $startTime, $res, $url, $payload);
            return ['error' => $res->json('error', 'Failed to send')];
        }
        $this->logRequest('sendFile', $uid, ['phone' => $to, 'file_type' => $ext], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function sendLocation(string $uid, string $token, string $to, float $lat, float $lng, array $options = []): array
    {
        $startTime = microtime(true);
        $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'location', 'location' => ['latitude' => $lat, 'longitude' => $lng]];
        if (isset($options['name'])) $payload['location']['name'] = $options['name'];
        if (isset($options['address'])) $payload['location']['address'] = $options['address'];
        $url = $this->graph . $uid . '/messages';
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendLocation', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $url, $payload);
            return ['error' => $res->json('error', 'Failed to send')];
        }
        $this->logRequest('sendLocation', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function sendButtons(string $uid, string $token, string $to, string $message, array $buttons, array $options = []): array
    {
        $startTime = microtime(true);
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $message],
                'action' => [
                    'buttons' => array_map(fn($b) => [
                        'type' => 'reply',
                        'reply' => [
                            'id' => $b['id'],
                            'title' => $b['label'] ?? $b['text'] ?? $b['title'] // Accept multiple formats
                        ]
                    ], $buttons)
                ]
            ]
        ];
        if (isset($options['fileUrl'])) {
            $ext = strtolower(pathinfo($options['fileUrl'], PATHINFO_EXTENSION));
            $mediaType = $this->mapType($ext);
            if ($mediaType === 'invalid') return ['error' => 'Invalid file extension'];
            $payload['interactive']['header'] = ['type' => $mediaType, $mediaType => ['link' => $options['fileUrl']]];
        }
        $url = $this->graph . $uid . '/messages';
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendButtons', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $url, $payload);
            return ['error' => $res->json('error', 'Failed to send')];
        }
        $this->logRequest('sendButtons', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function sendButtonLink(string $uid, string $token, string $to, string $message, string $url, string $label, array $options = []): array
    {
        $startTime = microtime(true);
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'cta_url',
                'body' => ['text' => $message],
                'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => $label, 'url' => $url]]
            ]
        ];
        $requestUrl = $this->graph . $uid . '/messages';
        $res = Http::withToken($token)->post($requestUrl, $payload);
        if ($res->failed()) {
            $this->logRequest('sendButtonLink', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $requestUrl, $payload);
            return ['error' => $res->json('error', 'Failed to send')];
        }
        $this->logRequest('sendButtonLink', $uid, ['phone' => $to], $startTime, $res, $requestUrl, $payload);
        return MessageResource::make($res->json());
    }

    public function sendOptionList(string $uid, string $token, string $to, string $message, string $buttonLabel, array $optionsList, array $extra = []): array
    {
        $startTime = microtime(true);
        // Support both formats: with sections or flat array
        // If first element has 'rows' key, it's already in sections format
        // Otherwise, wrap it in a section
        if (isset($optionsList[0]['rows'])) {
            // Already has sections structure
            $sections = array_map(fn($section) => [
                'title' => $section['title'] ?? 'Options',
                'rows' => array_map(fn($row) => [
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'description' => $row['description'] ?? ''
                ], $section['rows'])
            ], $optionsList);
        } else {
            // Flat array, wrap in a section
            $sections = [[
                'title' => 'Options',
                'rows' => array_map(fn($o) => [
                    'id' => $o['id'],
                    'title' => $o['title'],
                    'description' => $o['description'] ?? ''
                ], $optionsList)
            ]];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $message],
                'action' => [
                    'button' => $buttonLabel,
                    'sections' => $sections
                ]
            ]
        ];
        $url = $this->graph . $uid . '/messages';
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendOptionList', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $url, $payload);
            return ['error' => $res->json('error', 'Failed to send')];
        }
        $this->logRequest('sendOptionList', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function sendPoll(string $uid, string $token, string $to, string $message, array $pollOptions, array $options = []): array
    {
        return ['error' => 'Not supported'];
    }

    public function sendLink(string $uid, string $token, string $to, string $message, string $linkUrl, array $options = []): array
    {
        $startTime = microtime(true);
        $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $message . ' ' . $linkUrl]];
        $url = $this->graph . $uid . '/messages';
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendLink', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $url, $payload);
            return ['error' => $res->json('error', 'Failed to send')];
        }
        $this->logRequest('sendLink', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function sendEvent(string $uid, string $token, string $toGroupPhone, array $event, array $options = []): array
    {
        return ['error' => 'Not supported'];
    }

    public function sendTemplate(string $uid, string $token, string $to, string $name, string $languageCode, array $components): array
    {
        $startTime = microtime(true);
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $name,
                'language' => ['code' => $languageCode],
                'components' => $components
            ]
        ];
        $url = $this->graph . $uid . '/messages';
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendTemplate', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $url, $payload);
            return ['error' => $res->json('error', 'Failed to send')];
        }
        $this->logRequest('sendTemplate', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    private function mapType(string $ext): string
    {
        return [
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image',
            'gif' => 'video', 'mp4' => 'video', 'mov' => 'video',
            'mp3' => 'audio', 'ogg' => 'audio', 'aac' => 'audio', 'm4a' => 'audio', 'opus' => 'audio', 'wav' => 'audio',
            'pdf' => 'document', 'doc' => 'document', 'docx' => 'document'
        ][$ext] ?? 'invalid';
    }

    public function pinMessage(string $uid, string $token, string $phone, string $messageId, string $duration): array
    {
        return ['error' => 'Not supported'];
    }

    public function addContacts(string $uid, string $token, array $contacts): array
    {
        return ['error' => 'Not supported'];
    }

    public function contact(string $uid, string $token, string $phone): array
    {
        return ['error' => 'Not supported'];
    }

    public function contacts(string $uid, string $token, array $options = []): array
    {
        return ['error' => 'Not supported'];
    }

    public function sendContact(string $uid, string $token, string $to, string $contactName, string $contactPhone, array $options = []): array
    {
        $startTime = microtime(true);
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'contacts',
            'contacts' => [[
                'name' => ['formatted_name' => $contactName, 'first_name' => $contactName],
                'phones' => [[ 'phone' => $contactPhone, 'wa_id' => $contactPhone ]]
            ]]
        ];
        $url = $this->graph . $uid . '/messages';
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendContact', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $url, $payload);
            return ['error' => $res->json('error', 'Failed to send')];
        }
        $this->logRequest('sendContact', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function listTemplates(string $wabaId, string $token, array $params = []): array
    {
        $startTime = microtime(true);
        $url = $this->graph . $wabaId . '/message_templates';
        if (!empty($params)) {
            $query = [];
            if (isset($params['limit'])) $query['limit'] = $params['limit'];
            if (isset($params['after'])) $query['after'] = $params['after'];
            $url .= '?' . http_build_query($query);
        }
        $res = Http::withToken($token)->get($url);
        if ($res->failed()) {
            $this->logRequest('listTemplates', $wabaId, ['error' => $res->json('error', 'Failed to list')], $startTime, $res, $url);
            return ['error' => $res->json('error', 'Failed to list')];
        }
        $this->logRequest('listTemplates', $wabaId, [], $startTime, $res, $url);
        return $res->json();
    }

    public function getTemplate(string $wabaId, string $token, string $name): array
    {
        $startTime = microtime(true);
        $url = $this->graph . $wabaId . '/message_templates?name=' . urlencode($name);
        $res = Http::withToken($token)->get($url);
        if ($res->failed()) {
            $this->logRequest('getTemplate', $wabaId, ['error' => $res->json('error', 'Failed to get')], $startTime, $res, $url);
            return ['error' => $res->json('error', 'Failed to get')];
        }
        $this->logRequest('getTemplate', $wabaId, [], $startTime, $res, $url);
        return $res->json();
    }

    public function createTemplate(string $wabaId, string $token, array $data): array
    {
        $startTime = microtime(true);
        $header = (!empty($data['header']) || !empty($data['file']))
            ? WhatsAppCloudHelper::getHeaderTemplate($data['type'], $data['header'] ?? null, $data['file'] ?? null, $token, $this->graph)
            : [];
        $body = WhatsAppCloudHelper::getBodyTemplate($data['body'], $data['params'][0]['body'] ?? null);
        $footer = !empty($data['footer']) ? ['type' => 'FOOTER', 'text' => $data['footer']] : [];
        $buttons = !empty($data['buttons']) ? WhatsAppCloudHelper::getButtonsTemplate($data['buttons']) : [];
        $components = array_values(array_filter(!empty($header) ? [$header, $body, $footer, $buttons] : [$body, $footer, $buttons]));
        $payload = [
            'name' => $data['name'],
            'language' => $data['language_code'],
            'category' => $data['category'],
            'components' => $components
        ];
        $url = $this->graph . $wabaId . '/message_templates';
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('createTemplate', $wabaId, ['error' => $res->json('error', 'Failed to create')], $startTime, $res, $url, $payload);
            return ['error' => $res->json('error', 'Failed to create')];
        }
        $this->logRequest('createTemplate', $wabaId, [], $startTime, $res, $url, $payload);
        return $res->json();
    }

    public function updateTemplate(string $templateUid, string $token, array $data): array
    {
        $startTime = microtime(true);
        $header = (!empty($data['header']) || !empty($data['file']))
            ? WhatsAppCloudHelper::getHeaderTemplate($data['type'], $data['header'] ?? null, $data['file'] ?? null, $token, $this->graph)
            : [];
        $body = WhatsAppCloudHelper::getBodyTemplate($data['body'], $data['params'][0]['body'] ?? null);
        $footer = !empty($data['footer']) ? ['type' => 'FOOTER', 'text' => $data['footer']] : [];
        $buttons = !empty($data['buttons']) ? WhatsAppCloudHelper::getButtonsTemplate($data['buttons']) : [];
        $components = array_values(array_filter(!empty($header) ? [$header, $body, $footer, $buttons] : [$body, $footer, $buttons]));
        $payload = [
            'category' => $data['category'],
            'components' => $components
        ];
        $url = $this->graph . $templateUid;
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('updateTemplate', $templateUid, ['error' => $res->json('error', 'Failed to update')], $startTime, $res, $url, $payload);
            return ['error' => $res->json('error', 'Failed to update')];
        }
        $this->logRequest('updateTemplate', $templateUid, [], $startTime, $res, $url, $payload);
        return $res->json();
    }

    public function deleteTemplate(string $wabaId, string $token, string $name, string $uid): array
    {
        $startTime = microtime(true);
        $url = $this->graph . $wabaId . '/message_templates';
        $res = Http::withToken($token)->delete($url, ['name' => $name, 'hsm_id' => $uid]);
        if ($res->failed()) {
            $this->logRequest('deleteTemplate', $wabaId, ['error' => $res->json('error', 'Failed to delete')], $startTime, $res, $url);
            return ['error' => $res->json('error', 'Failed to delete')];
        }
        $this->logRequest('deleteTemplate', $wabaId, [], $startTime, $res, $url);
        return $res->json();
    }

    public function uploadHeaderHandle(string $token, string $fileKey): array
    {
        $startTime = microtime(true);
        $appId = config('wapi-gateway.meta_app_id');
        $fileUrl = config('wapi-gateway.aws_bucket_url') . '/' . $fileKey;
        $fileContent = @file_get_contents($fileUrl);
        if ($fileContent === false) return ['error' => 'File not found'];
        $fileSize = strlen($fileContent);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $fileMimeType = $finfo->buffer($fileContent);
        $sessionUrl = $this->graph . $appId . '/uploads?file_length=' . $fileSize . '&file_type=' . $fileMimeType;
        $session = Http::withToken($token)->post($sessionUrl, []);
        if ($session->failed() || $session->json('error')) {
            $this->logRequest('uploadHeaderHandle', $appId, ['error' => $session->json('error')], $startTime, $session, $sessionUrl);
            return ['error' => $session->json('error')];
        }
        $sessionId = $session->json('id');
        $uploadUrl = $this->graph . $sessionId;
        $response = Http::withHeaders(['Authorization' => 'OAuth ' . $token, 'file_offset' => 0, 'Content-Type' => $fileMimeType])
            ->withBody($fileContent, 'application/octet-stream')
            ->post($uploadUrl);
        if ($response->failed() || $response->json('error')) {
            $this->logRequest('uploadHeaderHandle', $appId, ['error' => $response->json('error')], $startTime, $response, $uploadUrl);
            return ['error' => $response->json('error')];
        }
        $this->logRequest('uploadHeaderHandle', $appId, [], $startTime, $response, $uploadUrl);
        return $response->json();
    }
}
