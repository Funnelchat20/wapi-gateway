<?php

namespace Funnelchat\WapiGateway\Helpers;

use Funnelchat\WapiGateway\Enums\MessageTemplateTypeButtonEnum;
use Funnelchat\WapiGateway\Enums\MessageTemplateTypeEnum;
use Illuminate\Support\Facades\Http;
use finfo;

class WhatsAppCloudHelper
{
    /**
     * A Business-Scoped User ID is the opaque, per-business identifier Meta
     * hands out instead of a phone number for contacts that reach the business
     * through a username without a recent interaction (rollout started
     * 2026-07-29). Those contacts have no phone on the inbound at all, so the
     * BSUID is the only way to answer them.
     *
     * Shape: an ISO 3166 alpha-2 country code, a dot, and up to 128 alphanumeric
     * characters (CO.1021346770783737). Portfolios may insert extra segments —
     * the enterprise form US.ENT.11815799212886844830 is the one seen so far,
     * but the segment list is left open rather than pinned to `ENT`.
     *
     * This rule must stay at least as permissive as `conversations`'
     * ContactService::isNonPhoneIdentifier(), which is what decides that a
     * contact has no phone and stores the BSUID in `whatsapp_id` in the first
     * place. Anything that side routes here as a non-phone and this side reads
     * as a phone goes out in `to` and the send fails — the exact bug BSUID
     * support exists to remove. So the two divergences that matter are matched
     * deliberately: a case-insensitive country code, and any number of
     * dot-separated segments. The mirror additionally accepts Z-API's `@lid`
     * suffix, which has no equivalent on the Cloud API and is out of scope here;
     * that is the only intended difference between the two.
     *
     * Being permissive costs nothing on the axis that is actually dangerous. The
     * failure modes are not symmetric — classifying a phone as a BSUID would move
     * the destination into the wrong payload field and break the ~97% of traffic
     * that is a plain phone — but a phone can never match this pattern at all: it
     * contains neither letters nor dots. The safety comes from requiring two
     * letters followed by a dot, not from tightening the alphabet.
     *
     * The country code is not validated against the ISO 3166 register on purpose:
     * the shape already rules out collisions with a phone, and an enumerated list
     * would silently reject codes Meta starts emitting later.
     */
    public static function isBsuid(?string $identifier): bool
    {
        if ($identifier === null) {
            return false;
        }

        // Kept character-for-character in step with the mirror's pattern (minus
        // its `@lid` branch). The {1,128} bound is Meta's documented id length:
        // an unbounded `+` would accept an over-long junk value here that the
        // mirror rejects, drifting the two rules apart in silence.
        return (bool) preg_match('/^[A-Za-z]{2}\.(?:[A-Za-z0-9]+\.)*[A-Za-z0-9]{1,128}$/', trim($identifier));
    }

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
