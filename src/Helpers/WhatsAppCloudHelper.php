<?php

namespace Funnelchat\WapiGateway\Helpers;

use Funnelchat\WapiGateway\Enums\MessageTemplateTypeButtonEnum;
use Funnelchat\WapiGateway\Enums\MessageTemplateTypeEnum;
use Illuminate\Support\Facades\Http;
use finfo;

class WhatsAppCloudHelper
{
    public static function getHeaderTemplate(string $typeTemplate, ?string $header, ?string $file, string $token, string $apiUrl): array
    {
        $type = MessageTemplateTypeEnum::from(strtolower($typeTemplate));
        if ($type === MessageTemplateTypeEnum::TEXT) {
            return [
                'type' => 'HEADER',
                'format' => MessageTemplateTypeEnum::TEXT->value,
                'text' => $header
            ];
        }

        $headerHandle = self::uploadFileHeaderHandle($file, $token, $apiUrl);
        return [
            'type' => 'HEADER',
            'format' => strtolower($typeTemplate),
            'example' => [
                'header_handle' => !empty($headerHandle['h']) ? $headerHandle['h'] : null
            ]
        ];
    }

    public static function getBodyTemplate(string $body, ?array $params): array
    {
        $bodyParam = self::getParamsTemplate($params);
        if (!empty($bodyParam)) {
            return [
                'type' => 'BODY',
                'text' => $body,
                'example' => [
                    'body_text' => [$bodyParam]
                ]
            ];
        }
        return [
            'type' => 'BODY',
            'text' => $body
        ];
    }

    public static function getParamsTemplate(?array $params): array
    {
        if (empty($params)) {
            return [];
        }
        $flat = [];
        array_walk_recursive($params, function ($value) use (&$flat) {
            $flat[] = (string) $value;
        });
        return $flat;
    }

    public static function getButtonsTemplate(array $buttonsParam): array
    {
        $buttons = [
            'type' => 'BUTTONS',
            'buttons' => []
        ];
        foreach ($buttonsParam as $button) {
            $buttonData = [
                'type' => $button['type'],
                'text' => $button['text']
            ];
            $buttonData = match ($button['type']) {
                MessageTemplateTypeButtonEnum::PHONE_NUMBER->value => array_merge($buttonData, ['phone_number' => $button['phone_number']]),
                MessageTemplateTypeButtonEnum::URL->value => array_merge($buttonData, ['url' => $button['url']]),
                default => $buttonData,
            };
            $buttons['buttons'][] = $buttonData;
        }
        return $buttons;
    }

    public static function uploadFileHeaderHandle($file, $token, $apiUrl)
    {
        $fileUrl = config('wapi-gateway.aws_bucket_url') . '/' . $file;
        $fileContent = @file_get_contents($fileUrl);
        if ($fileContent === false) {
            return ['error' => 'File not found'];
        }

        $fileSize = strlen($fileContent);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $fileMimeType = $finfo->buffer($fileContent);
        $url = $apiUrl . '/' . config('wapi-gateway.meta_app_id') . '/uploads?file_length=' . $fileSize . '&file_type=' . $fileMimeType;
        $session = Http::withToken($token)->post($url, []);
        if ($session->failed() || $session->json('error')) {
            \Sentry\captureMessage('WhatsAppCloudHelper uploadFileHeaderHandle session : ' . json_encode($session->json()));
            return ['error' => $session->json('error')];
        }
        $sessionId = $session->json('id');
        $defaultHeaders = ['Authorization' => 'OAuth ' . $token, 'file_offset' => 0, 'Content-Type' => $fileMimeType];
        $uploadUrl = $apiUrl . $sessionId;
        $response = Http::withHeaders($defaultHeaders)
            ->withBody($fileContent, 'application/octet-stream')
            ->post($uploadUrl);
        if ($response->failed() || $response->json('error')) {
            \Sentry\captureMessage('WhatsAppCloudHelper uploadFileHeaderHandle response : ' . json_encode($response->json()));
            return ['error' => $response->json('error')];
        }
        return $response->json();
    }
}
