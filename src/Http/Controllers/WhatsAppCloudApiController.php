<?php

namespace Funnelchat\WapiGateway\Http\Controllers;

use Funnelchat\WapiGateway\Enums\FileExtensionEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Funnelchat\WapiGateway\Helpers\BucketFile;
use Funnelchat\WapiGateway\Helpers\WhatsAppCloudHelper;
use Illuminate\Support\Facades\Http;
use finfo;
use Funnelchat\WapiGateway\Http\Resources\Meta\MessageResourse;
use Funnelchat\WapiGateway\Http\Resources\Meta\MessageTemplateCollectResourse;
use Illuminate\Validation\Rule;
use Funnelchat\WapiGateway\Enums\MessageTemplateTypeEnum;
use Funnelchat\WapiGateway\Enums\MessageTemplateTypeButtonEnum;
use Funnelchat\WapiGateway\Jobs\MessageApp\TemplateStatusObservationJob;
use Funnelchat\WapiGateway\Jobs\MessageApp\TemplateUpdatedStatusObservationJob;

class WhatsAppCloudApiController
{
    private string $apiUrl = 'https://graph.facebook.com/v20.0/';
    private const DEFAULT_MESSAGE_ERROR = 'An error occurred while sending the message.';
    private const DEFAULT_MESSAGE_ERROR_FILE = 'No se encontró el archivo.';
    private string|null|object $uid;
    private string|array|null $token;

    public function __construct()
    {
        $this->uid = request()->route('uid');
        $this->token = request()->header('token');
    }

    private function sendHttpRequest($endpoint, $method, $params)
    {
        $url = $this->apiUrl . $this->uid . '/' . $endpoint;
        info('whatsappCloud.sendHttpRequest.start', [
            'event' => 'whatsappCloud.sendHttpRequest.start',
            'step' => 'WhatsAppCloudApiController@sendHttpRequest',
            'uid' => $this->uid,
            'method' => $method,
            'url' => $url,
            'endpoint' => $endpoint,
            'params' => $params,
            'timestamp' => now()->toIso8601String(),
        ]);
        return Http::withToken($this->token)->{$method}($url, $params);
    }

    private function sendHttpRequestTemplates($endpoint, $method, $params, $headersData = [])
    {
        $url = $this->apiUrl . '/' . $endpoint;
        info('whatsappCloud.sendHttpRequestTemplates.start', [
            'event' => 'whatsappCloud.sendHttpRequestTemplates.start',
            'step' => 'WhatsAppCloudApiController@sendHttpRequestTemplates',
            'method' => $method,
            'url' => $url,
            'endpoint' => $endpoint,
            'headers' => $headersData,
            'params' => $params,
            'timestamp' => now()->toIso8601String(),
        ]);
        return Http::withToken($this->token)->withHeaders($headersData)->{$method}($url, $params);
    }

    public function me(Request $request)
    {
    }

    public function sendMessage(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required|string',
            'message' => 'required|string',
        ]);
        $response = self::sendHttpRequest('messages', 'post', [
            'messaging_product' => 'whatsapp',
            'to' => $validated['phone'],
            'type' => 'text',
            'text' => ['body' => $validated['message']],
        ]);
        if ($response->failed()) {
            return response()->json(['error' => $response->json('error', self::DEFAULT_MESSAGE_ERROR)], $response->status());
        }
        return new MessageResourse($response->json());
    }

    public function sendTemplates(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['string', 'required'],
            'name' => ['string', 'required'],
            'language_code' => ['string', 'required'],
            'components' => ['array', 'required'],
        ]);
        $response = self::sendHttpRequest('messages', 'POST', [
            'messaging_product' => 'whatsapp',
            'to' => $validated['phone'],
            'type' => 'template',
            'template' => [
                'name' => $validated['name'],
                'language' => ['code' => $validated['language_code']],
                'components' => $validated['components']
            ]
        ]);
        if ($response->failed()) {
            return response()->json(['error' => $response->json('error', self::DEFAULT_MESSAGE_ERROR)], $response->status());
        }
        return new MessageResourse($response->json());
    }

    public function sendFile(Request $request): JsonResponse|MessageResourse
    {
        $validated = $request->validate([
            'phone' => ['string', 'required'],
            'fileUrl' => ['url', 'required'],
            'fileName' => ['sometimes', 'string'],
            'caption' => ['nullable', 'string'],
        ]);
        $fileExtensionEnum = FileExtensionEnum::from(strtolower(pathinfo($validated['fileUrl'], PATHINFO_EXTENSION)));
        $type = $fileExtensionEnum->type();
        $params = [
            'messaging_product' => 'whatsapp',
            'to' => $validated['phone'],
            'type' => $type,
            $type => ['link' => $validated['fileUrl']],
        ];
        if (!empty($validated['fileName']) && $fileExtensionEnum->isDocument()) {
            $params[$type]['filename'] = $validated['fileName'];
        }
        if (!empty($validated['caption']) && $fileExtensionEnum->canHaveCaption()) {
            $params[$type]['caption'] = $validated['caption'];
        }
        $response = self::sendHttpRequest('messages', 'POST', $params);
        if ($response->failed()) {
            return response()->json(['error' => $response->json('error', self::DEFAULT_MESSAGE_ERROR)], $response->status());
        }
        return new MessageResourse($response->json());
    }

    public function sendButtons(Request $request): JsonResponse|MessageResourse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'message' => ['required', 'string'],
            'buttons' => ['required', 'array', 'max:3'],
            'buttons.*.id' => ['required', 'string'],
            'buttons.*.label' => ['required', 'string'],
            'fileUrl' => ['sometimes', 'url']
        ]);
        $params = [
            'messaging_product' => 'whatsapp',
            'to' => $validated['phone'],
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => [
                    'text' => $validated['message']
                ],
                'action' => [
                    'buttons' => array_map(fn($button) => [
                        'type' => 'reply',
                        'reply' => ['id' => $button['id'], 'title' => $button['label']]
                    ], $validated['buttons'])
                ]
            ]
        ];
        if (!empty($validated['fileUrl'])) {
            $ext = strtolower(pathinfo($validated['fileUrl'], PATHINFO_EXTENSION));
            $type = FileExtensionEnum::from($ext)->type();
            if ($type === 'Invalid') {
                return response()->json(['error' => 'Invalid file extension'], 400);
            }
            $params['interactive']['header'] = [
                'type' => $type,
                $type => [
                    'link' => $validated['fileUrl']
                ]
            ];
        }
        $response = self::sendHttpRequest('messages', 'POST', $params);
        if ($response->failed()) {
            return response()->json(['error' => $response->json('error', self::DEFAULT_MESSAGE_ERROR)], $response->status());
        }
        return new MessageResourse($response->json());
    }

    public function sendButtonLink(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'message' => ['required', 'string'],
            'url' => ['required', 'url'],
            'label' => ['required', 'string'],
        ]);
        $params = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $validated['phone'],
            'type' => 'interactive',
            'interactive' => [
                'type' => 'cta_url',
                'body' => [
                    'text' => $validated['message']
                ],
                'action' => [
                    'name' => 'cta_url',
                    'parameters' => [
                        'display_text' => $validated['label'],
                        'url' => $validated['url']
                    ]
                ]
            ],
        ];
        $response = self::sendHttpRequest('messages', 'POST', $params);
        if ($response->failed()) {
            return response()->json(['error' => $response->json('error', self::DEFAULT_MESSAGE_ERROR)], $response->status());
        }
        return new MessageResourse($response->json());
    }

    public function sendContact(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['string', 'required'],
            'contactName' => ['string', 'required'],
            'contactPhone' => ['string', 'required']
        ]);
        $params = [
            'messaging_product' => 'whatsapp',
            'to' => $validated['phone'],
            'type' => 'contacts',
            'contacts' => [
                [
                    'name' => [
                        'formatted_name' => $validated['contactName'],
                        'first_name' => $validated['contactName']
                    ],
                    'phones' => [
                        [
                            'phone' => $validated['contactPhone'],
                            'wa_id' => $validated['contactPhone']
                        ]
                    ]
                ]
            ]
        ];
        $response = self::sendHttpRequest('messages', 'POST', $params);
        if ($response->failed()) {
            return response()->json(['error' => $response->json('error', self::DEFAULT_MESSAGE_ERROR)], $response->status());
        }
        return new MessageResourse($response->json());
    }

    public function sendLocation(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['string', 'required'],
            'latitude' => ['string', 'required'],
            'longitude' => ['string', 'required'],
            'name' => ['string', 'required'],
            'address' => ['string', 'required'],
        ]);
        $response = self::sendHttpRequest('messages', 'POST', [
            'messaging_product' => 'whatsapp',
            'to' => $validated['phone'],
            'type' => 'location',
            'location' => [
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'name' => $validated['name'],
                'address' => $validated['address'],
            ]
        ]);
        if ($response->failed()) {
            return response()->json(['error' => $response->json('error', self::DEFAULT_MESSAGE_ERROR)], $response->status());
        }
        return new MessageResourse($response->json());
    }

    public function sendImageMessage(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required|string',
            'fileUrl' => 'required|url',
        ]);
        return $this->sendHttpRequest('messages', 'post', [
            'messaging_product' => 'whatsapp',
            'to' => $validated['phone'],
            'type' => 'image',
            'image' => [
                'link' => $validated['fileUrl'],
            ],
        ])->json();
    }

    public function sendDocumentMessage(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required|string',
            'caption' => 'required|string',
            'fileUrl' => 'required|url',
        ]);
        return $this->sendHttpRequest('messages', 'post', [
            'messaging_product' => 'whatsapp',
            'to' => $validated['phone'],
            'type' => 'document',
            'document' => [
                'link' => $validated['fileUrl'],
                'caption' => $validated['caption'],
            ],
        ])->json();
    }

    public function sendOptionList(Request $request): JsonResponse|MessageResourse
    {
        $validated = $request->validate([
            'phone' => ['string', 'required'],
            'message' => ['string', 'required'],
            'buttonLabel' => ['string', 'required'],
            'optionList' => ['array', 'required'],
            'optionList.*.id' => ['string', 'required'],
            'optionList.*.title' => ['string', 'required']
        ]);
        $params = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $validated['phone'],
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => [
                    'text' => $validated['message']
                ],
                'action' => [
                    'button' => $validated['buttonLabel'],
                    'sections' => [
                        [
                            'title' => 'LIST',
                            'rows' => array_map(fn($option) => [
                                'id' => $option['id'],
                                'title' => $option['title']
                            ], $validated['optionList'])
                        ]
                    ]
                ]
            ]
        ];
        $response = self::sendHttpRequest('messages', 'POST', $params);
        if ($response->failed()) {
            return response()->json(['error' => $response->json('error', self::DEFAULT_MESSAGE_ERROR)], $response->status());
        }
        return new MessageResourse($response->json());
    }

    public function getTemplates(Request $request)
    {
        $validated = $request->validate([
            'waba-id' => 'required|string',
            'next_page_url' => 'nullable|url',
            'limit' => 'nullable|string',
            'after' => 'nullable|string'
        ]);
        $response = isset($validated['next_page_url']) && !empty($validated['next_page_url'])
            ? Http::withToken($this->token)->get($validated['next_page_url'] . '?limit=' . $validated['limit'] . '&after=' . $validated['after'])
            : self::sendHttpRequestTemplates($validated['waba-id'] . '/message_templates', 'get', []);
        if ($response->failed()) {
            return response()->json(['error' => $response->json('error', self::DEFAULT_MESSAGE_ERROR)], $response->status());
        }
        return new MessageTemplateCollectResourse($response->json());
    }

    public function getTemplate(Request $request)
    {
        $validated = $request->validate([
            'waba-id' => 'required|string',
            'name' => 'required|string'
        ]);
        $response = self::sendHttpRequestTemplates($validated['waba-id'] . '/message_templates?name=' . $validated['name'], 'get', []);
        if ($response->status() >= 400) {
            $errorMessage = $response->json('error');
            return response()->json(['error' => $errorMessage], $response->status());
        }
        return $response->json();
    }

    public function uploadFileHeaderHandle(Request $request)
    {
        $validated = $request->validate([
            'file' => ['string', 'required'],
            'user_id' => ['int', 'required'],
        ]);
        $downloaded = BucketFile::fetch($validated['user_id'] . '/' . $validated['file']);
        $fileContent = $downloaded['content'] ?? null;
        if ($fileContent) {
            $fileSize = strlen($fileContent);
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $fileMimeType = $finfo->buffer($fileContent);
            $session = self::sendHttpRequestTemplates(config('wapi-gateway.meta_app_id') . '/uploads?file_length=' . $fileSize . '&file_type=' . $fileMimeType, 'post', []);
            if ($session->failed() || $session->json('error')) {
                $errorMessage = $session->json('error');
                return response()->json(['error' => $errorMessage], $session->status());
            }
            $sesion_file = $session->json('id');
            $defaultHeaders = ['Authorization' => 'OAuth ' . $this->token, 'file_offset' => 0, 'Content-Type' => $fileMimeType];
            $url = $this->apiUrl . $sesion_file;
            $response = Http::withHeaders($defaultHeaders)
                ->withBody($fileContent, 'application/octet-stream')
                ->post($url);
            if ($response->failed() || $response->json('error')) {
                $errorMessage = $response->json('error');
                return response()->json(['error' => $errorMessage], $response->status());
            }
            return $response->json();
        }
        return response()->json(['error' => self::DEFAULT_MESSAGE_ERROR_FILE], 400);
    }

    public function createTemplates(Request $request)
    {
        $validated = $request->validate([
            'template_id' => 'required|int',
            'waba-id' => 'required|string',
            'name' => ['string', 'required'],
            'language_code' => ['string', 'required'],
            'category' => ['string', 'required'],
            'type' => ['string', 'required', Rule::in(MessageTemplateTypeEnum::cases())],
            'header' => ['nullable', 'string'],
            'body' => ['string', 'required'],
            'params' => ['array', 'nullable'],
            'footer' => ['string', 'nullable'],
            'file' => ['nullable', 'string', 'required_if:type,!' . MessageTemplateTypeEnum::TEXT->value],
            'buttons' => ['nullable', 'array', 'max:3'],
            'buttons.*.text' => ['required_with:buttons', 'string'],
            'buttons.*.type' => ['required_with:buttons', Rule::in(MessageTemplateTypeButtonEnum::cases())],
            'buttons.*.url' => ['sometimes', 'url'],
            'buttons.*.phone_number' => ['sometimes', 'string']
        ]);
        $header = !empty($validated['header']) || !empty($validated['file'])
            ? WhatsAppCloudHelper::getHeaderTemplate(
                $validated['type'],
                $validated['header'],
                $validated['file'],
                $this->token,
                $this->apiUrl
            )
            : [];
        $body = WhatsAppCloudHelper::getBodyTemplate($validated['body'], $validated['params'][0]['body'] ?? null);
        $footer = '';
        if (isset($validated['footer']) && !empty($validated['footer'])) {
            $footer = ['type' => 'FOOTER', 'text' => $validated['footer']];
        }
        $buttons = '';
        if (!empty($validated['buttons'])) {
            $buttons = WhatsAppCloudHelper::getButtonsTemplate($validated['buttons']);
        }
        $components = !empty($header)
            ? [$header, $body, $footer, $buttons]
            : [$body, $footer, $buttons];
        $components = array_filter($components, function ($component) {
            return !empty($component);
        });
        $response = self::sendHttpRequestTemplates($validated['waba-id'] . '/message_templates', 'post', [
            'name' => $validated['name'],
            'language' => $validated['language_code'],
            'category' => $validated['category'],
            'components' => array_values($components)
        ]);
        if ($response->failed() || $response->json('error')) {
            \Sentry\captureMessage('createTemplates ' . json_encode($response->json()));
        }
        $templateId = $validated['template_id'];
        $header_handle = !empty($header['example']['header_handle']) ? $header['example']['header_handle'] : null;
        $messageTemplateId = $response->json('id', null);
        $status = $response->json('status', 'REJECTED');
        $errorOptional = data_get($response->json(), 'error.message', null);
        $error = data_get($response->json(), 'error.error_user_msg', $errorOptional);
        TemplateStatusObservationJob::dispatch($templateId, $status, $error, $messageTemplateId, $header_handle)->onQueue('message-api-' . app()->environment());
        return response()->noContent();
    }

    public function delete(Request $request)
    {
        $validated = $request->validate([
            'waba-id' => 'required|string',
            'name' => 'required|string',
            'uid' => 'required|string',
        ]);
        $url = $this->apiUrl . $validated['waba-id'] . '/message_templates';
        $response = Http::withToken($this->token)
            ->delete($url, ['name' => $validated['name'], 'hsm_id' => $validated['uid']]);
        if ($response->failed()) {
            return response()->json(['error' => $response->json('error', self::DEFAULT_MESSAGE_ERROR)], $response->status());
        }
        return $response->json();
    }

    public function updateTemplates(Request $request)
    {
        $validated = $request->validate([
            'template_uid' => ['int', 'required'],
            'waba-id' => ['string', 'required'],
            'category' => ['string', 'required'],
            'type' => ['string', 'required', Rule::in(MessageTemplateTypeEnum::cases())],
            'header' => ['nullable', 'string'],
            'body' => ['string', 'required'],
            'params' => ['array', 'nullable'],
            'footer' => ['string', 'nullable'],
            'file' => ['nullable', 'string', 'required_if:type,!' . MessageTemplateTypeEnum::TEXT->value],
            'buttons' => ['nullable', 'array', 'max:3'],
            'buttons.*.text' => ['required_with:buttons', 'string'],
            'buttons.*.type' => ['required_with:buttons', Rule::in(MessageTemplateTypeButtonEnum::cases())],
            'buttons.*.url' => ['sometimes', 'url'],
            'buttons.*.phone_number' => ['sometimes', 'string']
        ]);
        $header = !empty($validated['header']) || !empty($validated['file'])
            ? WhatsAppCloudHelper::getHeaderTemplate(
                $validated['type'],
                $validated['header'],
                $validated['file'],
                $this->token,
                $this->apiUrl
            )
            : [];
        $body = WhatsAppCloudHelper::getBodyTemplate($validated['body'], $validated['params'][0]['body'] ?? null);
        $footer = [];
        if (!empty($validated['footer'])) {
            $footer = ['type' => 'FOOTER', 'text' => $validated['footer']];
        }
        $buttons = [];
        if (isset($validated['buttons']) && count($validated['buttons']) > 0) {
            $buttons = WhatsAppCloudHelper::getButtonsTemplate($validated['buttons']);
        }
        $components = !empty($header)
            ? [$header, $body, $footer, $buttons]
            : [$body, $footer, $buttons];
        $components = array_filter($components, function ($component) {
            return !empty($component);
        });
        $response = self::sendHttpRequestTemplates(
            $validated['template_uid'],
            'post',
            [
                'category' => $validated['category'],
                'components' => array_values($components)
            ]
        );
        if ($response->failed() || $response->json('error')) {
            logger()->error('updateTemplates', ['response' => $response->json()]);
        }
        $header_handle = !empty($header['example']['header_handle']) ? $header['example']['header_handle'] : null;
        $status = !empty($response->json('success')) ? $response->json('success') : json_encode($response->json());
        TemplateUpdatedStatusObservationJob::dispatch($validated['template_uid'], $status, $header_handle)->onQueue('message-api-' . app()->environment());
        return response()->noContent();
    }
}
