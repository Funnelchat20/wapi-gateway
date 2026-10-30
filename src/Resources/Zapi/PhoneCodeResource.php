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
     *
     * FunApi (same route/method, `api/internal/http/handlers/instances.go`)
     * shares none of this: it never sends `challenge`, and its success body
     * carries the code under `code`, not `value`. `value` is tried first
     * since it is the documented Z-API field.
     */
    public static function make(array $data): array
    {
        if (isset($data['challenge'])) {
            return ['code' => null, 'challenge' => true];
        }

        return ['code' => $data['value'] ?? $data['code'] ?? null, 'challenge' => false];
    }
}
