<?php

namespace Funnelchat\WapiGateway\Contracts;

interface InstancesContract
{
    public function create(int $userId, int $deviceId): array;

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
    public function logout(string $uid, string $token): array;
    public function reboot(string $uid, string $token): array;
    public function me(string $uid, string $token): array;
    public function checkPhone(string $uid, string $token, string $phone): array;
    public function checkPhoneWhatsapp(string $uid, string $token, string $phone): array;
    public function checkPhonesBatch(string $uid, string $token, array $phones): array;
    public function subscribe(string $uid, string $token): array;
    public function unsubscribe(string $uid, string $token): array;
    public function getParticipants(string $uid, string $token, string $phone): array;

    public function updateWebhookReceived(string $uid, string $token, int $userId, int $deviceId, bool $privateMessages = false): array;

    public function updateWebhookReceivedAndDelivery(string $uid, string $token, int $userId, int $deviceId): array;
}
