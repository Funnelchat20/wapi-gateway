<?php

namespace Funnelchat\WapiGateway\Clients;

use Funnelchat\WapiGateway\Contracts\MessagesContract;
use Funnelchat\WapiGateway\Contracts\InstancesContract;
use Funnelchat\WapiGateway\Contracts\ContactsContract;
use Funnelchat\WapiGateway\Contracts\TemplatesContract;
use Funnelchat\WapiGateway\Data\MessageResultData;
use Funnelchat\WapiGateway\Exceptions\UnsupportedOperationException;
use Funnelchat\WapiGateway\Exceptions\WapiException;
use Funnelchat\WapiGateway\Helpers\WhatsAppCloudHelper;
use Illuminate\Support\Facades\Http;

class MetaClient implements MessagesContract, InstancesContract, ContactsContract, TemplatesContract
{
    private const PROVIDER = 'meta';

    private string $graph;

    public function __construct()
    {
        $version = trim(config('wapi.meta.graph_version', 'v20.0'), '/');
        $this->graph = sprintf('https://graph.facebook.com/%s/', $version ?: 'v20.0');
    }

    public function sendText(string $uid, string $token, string $to, string $text, array $options = []): MessageResultData
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
            $error = $res->json('error.message') ?? $res->json('error', 'Failed to send');
            $code = $res->json('error.code');
            throw new WapiException(is_string($error) ? $error : 'Failed to send', self::PROVIDER, errorCode: $code ? (string) $code : null, rawError: $res->json());
        }
        return MessageResultData::fromMeta($res->json());
    }

    public function create(int $userId, int $deviceId): never
    {
        $this->unsupported(__FUNCTION__);
    }

    /**
     * @throws \Funnelchat\WapiGateway\Exceptions\UnsupportedOperationException
     */
    public function status(string $uid, string $token): never
    {
        $this->unsupported(__FUNCTION__);
    }

    /**
     * @throws \Funnelchat\WapiGateway\Exceptions\UnsupportedOperationException
     */
    public function qrCode(string $uid, string $token): never
    {
        $this->unsupported(__FUNCTION__);
    }

    /**
     * @throws \Funnelchat\WapiGateway\Exceptions\UnsupportedOperationException
     */
    public function logout(string $uid, string $token): never
    {
        $this->unsupported(__FUNCTION__);
    }

    /**
     * @throws \Funnelchat\WapiGateway\Exceptions\UnsupportedOperationException
     */
    public function reboot(string $uid, string $token): never
    {
        $this->unsupported(__FUNCTION__);
    }

    /**
     * @throws \Funnelchat\WapiGateway\Exceptions\UnsupportedOperationException
     */
    public function me(string $uid, string $token): never
    {
        $this->unsupported(__FUNCTION__);
    }

    /**
     * @throws \Funnelchat\WapiGateway\Exceptions\UnsupportedOperationException
     */
    public function checkPhone(string $uid, string $token, string $phone): never
    {
        $this->unsupported(__FUNCTION__);
    }

    /**
     * @throws \Funnelchat\WapiGateway\Exceptions\UnsupportedOperationException
     */
    public function subscribe(string $uid, string $token): never
    {
        $this->unsupported(__FUNCTION__);
    }

    /**
     * @throws \Funnelchat\WapiGateway\Exceptions\UnsupportedOperationException
     */
    public function unsubscribe(string $uid, string $token): never
    {
        $this->unsupported(__FUNCTION__);
    }

    /**
     * @throws \Funnelchat\WapiGateway\Exceptions\UnsupportedOperationException
     */
    public function getParticipants(string $uid, string $token, string $phone): never
    {
        $this->unsupported(__FUNCTION__);
    }

    public function sendFile(string $uid, string $token, string $to, string $fileUrl, array $options = []): MessageResultData
    {
        $ext = strtolower(pathinfo($fileUrl, PATHINFO_EXTENSION));
        $type = $this->mapType($ext);
        if ($type === 'invalid') {
            throw new WapiException('invalid_file_extension', self::PROVIDER);
        }
        $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => $type, $type => ['link' => $fileUrl]];
        if (isset($options['fileName']) && $type === 'document') $payload[$type]['filename'] = $options['fileName'];
        if (isset($options['caption']) && in_array($type, ['image', 'video', 'document'])) $payload[$type]['caption'] = $options['caption'];
        $res = Http::withToken($token)->post($this->graph . $uid . '/messages', $payload);
        if ($res->failed()) {
            $error = $res->json('error.message') ?? $res->json('error', 'Failed to send');
            $code = $res->json('error.code');
            throw new WapiException(is_string($error) ? $error : 'Failed to send', self::PROVIDER, errorCode: $code ? (string) $code : null, rawError: $res->json());
        }
        return MessageResultData::fromMeta($res->json());
    }

    public function sendLocation(string $uid, string $token, string $to, float $lat, float $lng, array $options = []): array
    {
        $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'location', 'location' => ['latitude' => $lat, 'longitude' => $lng]];
        if (isset($options['name'])) $payload['location']['name'] = $options['name'];
        if (isset($options['address'])) $payload['location']['address'] = $options['address'];
        $res = Http::withToken($token)->post($this->graph . $uid . '/messages', $payload);
        if ($res->failed()) return ['error' => $res->json('error', 'Failed to send')];
        return $res->json();
    }

    public function sendButtons(string $uid, string $token, string $to, string $message, array $buttons, array $options = []): array
    {
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
        $res = Http::withToken($token)->post($this->graph . $uid . '/messages', $payload);
        if ($res->failed()) return ['error' => $res->json('error', 'Failed to send')];
        return $res->json();
    }

    public function sendButtonLink(string $uid, string $token, string $to, string $message, string $url, string $label, array $options = []): array
    {
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
        $res = Http::withToken($token)->post($this->graph . $uid . '/messages', $payload);
        if ($res->failed()) return ['error' => $res->json('error', 'Failed to send')];
        return $res->json();
    }

    public function sendOptionList(string $uid, string $token, string $to, string $message, string $buttonLabel, array $optionsList, array $extra = []): array
    {
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
        $res = Http::withToken($token)->post($this->graph . $uid . '/messages', $payload);
        if ($res->failed()) return ['error' => $res->json('error', 'Failed to send')];
        return $res->json();
    }

    public function sendPoll(string $uid, string $token, string $to, string $message, array $pollOptions, array $options = []): never
    {
        $this->unsupported(__FUNCTION__);
    }

    public function sendLink(string $uid, string $token, string $to, string $message, string $linkUrl, array $options = []): array
    {
        $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $message . ' ' . $linkUrl]];
        $res = Http::withToken($token)->post($this->graph . $uid . '/messages', $payload);
        if ($res->failed()) return ['error' => $res->json('error', 'Failed to send')];
        return $res->json();
    }

    public function sendEvent(string $uid, string $token, string $toGroupPhone, array $event, array $options = []): never
    {
        $this->unsupported(__FUNCTION__);
    }

    public function sendTemplate(string $uid, string $token, string $to, string $name, string $languageCode, array $components): array
    {
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
        $res = Http::withToken($token)->post($this->graph . $uid . '/messages', $payload);
        if ($res->failed()) return ['error' => $res->json('error', 'Failed to send')];
        return $res->json();
    }

    private function unsupported(string $method): never
    {
        throw new UnsupportedOperationException("MetaClient does not support {$method}()");
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

    public function contact(string $uid, string $token, string $phone): never
    {
        $this->unsupported(__FUNCTION__);
    }

    public function contacts(string $uid, string $token, array $options = []): never
    {
        $this->unsupported(__FUNCTION__);
    }

    public function sendContact(string $uid, string $token, string $to, string $contactName, string $contactPhone, array $options = []): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'contacts',
            'contacts' => [[
                'name' => ['formatted_name' => $contactName, 'first_name' => $contactName],
                'phones' => [[ 'phone' => $contactPhone, 'wa_id' => $contactPhone ]]
            ]]
        ];
        $res = Http::withToken($token)->post($this->graph . $uid . '/messages', $payload);
        if ($res->failed()) return ['error' => $res->json('error', 'Failed to send')];
        return $res->json();
    }

    public function listTemplates(string $wabaId, string $token, array $params = []): array
    {
        $url = $this->graph . $wabaId . '/message_templates';
        if (!empty($params)) {
            $query = [];
            if (isset($params['limit'])) $query['limit'] = $params['limit'];
            if (isset($params['after'])) $query['after'] = $params['after'];
            $url .= '?' . http_build_query($query);
        }
        $res = Http::withToken($token)->get($url);
        if ($res->failed()) return ['error' => $res->json('error', 'Failed to list')];
        return $res->json();
    }

    public function getTemplate(string $wabaId, string $token, string $name): array
    {
        $url = $this->graph . $wabaId . '/message_templates?name=' . urlencode($name);
        $res = Http::withToken($token)->get($url);
        if ($res->failed()) return ['error' => $res->json('error', 'Failed to get')];
        return $res->json();
    }

    public function createTemplate(string $wabaId, string $token, array $data): array
    {
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
        $res = Http::withToken($token)->post($this->graph . $wabaId . '/message_templates', $payload);
        if ($res->failed()) return ['error' => $res->json('error', 'Failed to create')];
        return $res->json();
    }

    public function updateTemplate(string $templateUid, string $token, array $data): array
    {
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
        $res = Http::withToken($token)->post($this->graph . $templateUid, $payload);
        if ($res->failed()) return ['error' => $res->json('error', 'Failed to update')];
        return $res->json();
    }

    public function deleteTemplate(string $wabaId, string $token, string $name, string $uid): array
    {
        $url = $this->graph . $wabaId . '/message_templates';
        $res = Http::withToken($token)->delete($url, ['name' => $name, 'hsm_id' => $uid]);
        if ($res->failed()) return ['error' => $res->json('error', 'Failed to delete')];
        return $res->json();
    }

    public function uploadHeaderHandle(string $token, string $fileKey): array
    {
        $fileUrl = env('AWS_BUCKET_URL') . '/' . $fileKey;
        $fileContent = @file_get_contents($fileUrl);
        if ($fileContent === false) return ['error' => 'File not found'];
        $fileSize = strlen($fileContent);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $fileMimeType = $finfo->buffer($fileContent);
        $session = Http::withToken($token)->post($this->graph . env('META_APP_ID') . '/uploads?file_length=' . $fileSize . '&file_type=' . $fileMimeType, []);
        if ($session->failed() || $session->json('error')) return ['error' => $session->json('error')];
        $sessionId = $session->json('id');
        $response = Http::withHeaders(['Authorization' => 'OAuth ' . $token, 'file_offset' => 0, 'Content-Type' => $fileMimeType])
            ->withBody($fileContent, 'application/octet-stream')
            ->post($this->graph . $sessionId);
        if ($response->failed() || $response->json('error')) return ['error' => $response->json('error')];
        return $response->json();
    }
}
