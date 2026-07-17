<?php

namespace Funnelchat\WapiGateway\Contracts;

interface InstancesContract
{
    /**
     * Create a provider instance for a device. $countryCode is an optional
     * ISO alpha-2 hint (e.g. "CO"); FunApi uses it to assign a region-matched
     * proxy at connect time. Other providers ignore it.
     */
    public function create(int $userId, int $deviceId, ?string $countryCode = null): array;

    /**
     * Configure (enable) or disable the proxy of an existing instance through the
     * in-process Facade. A non-empty $proxyUrl enables it; an empty/null value
     * disables and clears it. Implementations must never throw: failures are
     * surfaced via the returned array (`['proxy_configured' => bool, 'error'? => string]`).
     */
    public function configureProxy(string $uid, string $token, ?string $proxyUrl): array;

    public function status(string $uid, string $token): array;
    public function qrCode(string $uid, string $token): array;

    /**
     * Request a short-lived extension token used by the Chrome extension
     * "Z-API Conector" to transfer an authenticated WhatsApp Web session
     * to this instance (fallback when the QR flow is blocked by Meta's
     * new biometric verification layer).
     *
     * Implementations must never throw: failures are surfaced via the
     * returned array. The array uses `error` as a discriminator when the
     * call fails and omits it on success:
     *
     * Success:      ['token' => 'XXXX-XXXX', 'expiresAt' => <epoch_ms>]
     * Rate-limited: ['error' => 'rate_limited', 'message' => ..., 'status' => 429, 'errorCode' => ...|null]
     * Upstream err: ['error' => 'upstream_error', 'message' => ..., 'status' => <http_status>, 'errorCode' => ...|null]
     * Unsupported:  ['error' => 'unsupported', 'message' => 'Extension token is not supported for this provider']
     */
    public function extensionToken(string $uid, string $token): array;

    /**
     * Request a short-lived session token for the Z-API Connector SDK, the
     * pre-built modal (window.ZAPIConnector) embedded on the frontend to
     * connect a WhatsApp session (QR / phone / extension migration) —
     * fallback when the QR flow is blocked by Meta's new verification layer.
     *
     * Implementations must never throw: failures are surfaced via the
     * returned array. The array uses `error` as a discriminator when the
     * call fails and omits it on success:
     *
     * Success:      ['token' => '<opaque session token>']
     * Rate-limited: ['error' => 'rate_limited', 'message' => ..., 'status' => 429, 'errorCode' => ...|null]
     * Upstream err: ['error' => 'upstream_error', 'message' => ..., 'status' => <http_status>, 'errorCode' => ...|null]
     * Unsupported:  ['error' => 'unsupported', 'message' => 'SDK connector token is not supported for this provider']
     */
    public function sdkConnectorToken(string $uid, string $token): array;
    public function logout(string $uid, string $token): array;
    public function reboot(string $uid, string $token): array;
    public function me(string $uid, string $token): array;

    /**
     * Fetch the WhatsApp Business profile info for the number (Z-API's
     * separate `GET /instances/{id}/token/{token}/business/profile`
     * endpoint — distinct from `me()`/`/device`). Only meaningful for
     * business accounts (`me().isBusiness === true`); used to feed the
     * device-quality Factor 7 "business_profile" scoring in accounts.
     *
     * Implementations must never throw: on a non-business account, an empty
     * upstream response, or a provider that does not support this endpoint,
     * return the same shape with empty/false values instead of an error
     * discriminator, so callers can treat "no data" uniformly.
     *
     * Shape (all keys always present):
     * [
     *   'description' => string,
     *   'website' => array,      // normalized from Z-API's `websites`
     *   'email' => string,
     *   'address' => string,
     *   'categories' => array,
     *   'businessHours' => array,
     *   'hasCoverPhoto' => bool,
     * ]
     */
    public function businessProfile(string $uid, string $token): array;

    public function checkPhone(string $uid, string $token, string $phone): array;
    public function checkPhoneWhatsapp(string $uid, string $token, string $phone): array;
    public function checkPhonesBatch(string $uid, string $token, array $phones): array;
    public function subscribe(string $uid, string $token): array;
    public function unsubscribe(string $uid, string $token): array;
    public function getParticipants(string $uid, string $token, string $phone): array;

    public function updateWebhookReceived(string $uid, string $token, int $userId, int $deviceId, bool $privateMessages = false): array;

    public function updateWebhookReceivedAndDelivery(string $uid, string $token, int $userId, int $deviceId): array;
}
