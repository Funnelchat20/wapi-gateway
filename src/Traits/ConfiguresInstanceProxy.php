<?php

namespace Funnelchat\WapiGateway\Traits;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared proxy-configuration plumbing for instance controllers.
 *
 * The per-provider upstream contract differs (URL, auth, body, enable/disable
 * semantics), so each controller implements {@see sendProxyConfig()}; everything
 * else — validation, the standalone endpoint, the create-time hook and error
 * handling — lives here.
 */
trait ConfiguresInstanceProxy
{
    /**
     * Accepted proxy URL schemes (http, https, socks4, socks5). An empty string
     * is allowed too so callers can clear/disable the proxy without relying on the
     * host app's ConvertEmptyStringsToNull middleware.
     */
    private const PROXY_URL_RULES = ['nullable', 'string', 'regex:/^$|^(https?|socks[45]):\/\/.+/i'];

    /** Max seconds to wait on the upstream proxy-config call. */
    private const PROXY_CONFIG_TIMEOUT = 10;

    /** Fallback error text when the upstream returns no error detail. */
    private const UNKNOWN_PROXY_ERROR = 'Unknown error';

    /**
     * Configure (or disable) the proxy for an already existing instance.
     * Pass `proxy_url` to enable it; omit/empty it to disable and clear the proxy.
     */
    public function configureProxy(Request $request, string $uid, string $token): JsonResponse
    {
        $validated = $request->validate(['proxy_url' => self::PROXY_URL_RULES]);
        try {
            $response = $this->sendProxyConfig($uid, $token, $validated['proxy_url'] ?? null);
            if ($this->proxyConfigFailed($response)) {
                return response()->json([
                    'message' => 'Failed to configure proxy',
                    'error' => $response->json('error') ?? self::UNKNOWN_PROXY_ERROR,
                ], Response::HTTP_BAD_GATEWAY);
            }
            return response()->json(['value' => $response->json('value')]);
        } catch (ConnectionException $e) {
            $error = $this->redactToken($e->getMessage(), $token);
            logger()->error('deviceUid #' . $uid . ' configure proxy failed (ConnectionException)', ['uid' => $uid, 'error' => $error]);
            return response()->json(['message' => $error], Response::HTTP_BAD_GATEWAY);
        } catch (\Throwable $e) {
            $error = $this->redactToken($e->getMessage(), $token);
            logger()->error('deviceUid #' . $uid . ' configure proxy failed (Throwable)', ['uid' => $uid, 'error' => $error]);
            return response()->json(['message' => $error], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Configure the proxy for a freshly created instance. Failures are logged but
     * do not abort instance creation. Returns whether the proxy was applied so the
     * caller can surface the outcome instead of it failing silently; returns false
     * when the create response lacked a usable uid/token.
     */
    private function applyProxyOnCreate(?string $uid, ?string $token, string $proxyUrl, int $deviceId): bool
    {
        if (empty($uid) || empty($token)) {
            return false;
        }
        try {
            $response = $this->sendProxyConfig($uid, $token, $proxyUrl);
            if ($this->proxyConfigFailed($response)) {
                logger()->error('deviceId #' . $deviceId . ' configureProxy error response', [
                    'error' => $response->json('error') ?? self::UNKNOWN_PROXY_ERROR,
                    'deviceId' => $deviceId,
                ]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            logger()->error('deviceId #' . $deviceId . ' configureProxy exception', [
                'error' => $this->redactToken($e->getMessage(), $token),
                'deviceId' => $deviceId,
            ]);
            return false;
        }
    }

    private function proxyConfigFailed(ClientResponse $response): bool
    {
        return $response->failed() || $response->json('error');
    }

    /** Strip the instance token from upstream error messages before logging/returning it. */
    private function redactToken(string $message, ?string $token): string
    {
        return empty($token) ? $message : str_replace($token, '***', $message);
    }

    /**
     * Perform the provider-specific proxy configuration request. A non-empty
     * `$proxyUrl` enables the proxy; an empty/null value disables and clears it.
     * `$uid` is passed verbatim — each provider normalizes it as needed (Z-API
     * strips a `-` suffix; Funapi keeps the full UUID).
     */
    abstract protected function sendProxyConfig(string $uid, string $token, ?string $proxyUrl): ClientResponse;
}
