<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class PhoneCodeResource
{
    /**
     * Z-API answers either a pairing code (`value`) or, for devices enrolled
     * in WhatsApp's passkey verification, a `challenge` object that must be
     * completed through a separate endpoint before the code is usable. That
     * challenge flow is not implemented here, so it surfaces as `code: null,
     * challenge: true` instead of a code the caller could show.
     */
    public static function make(array $data): array
    {
        if (isset($data['challenge'])) {
            return ['code' => null, 'challenge' => true];
        }

        return ['code' => $data['value'] ?? null, 'challenge' => false];
    }
}
