<?php

namespace Funnelchat\WapiGateway\Traits;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as ClientResponse;

/**
 * Request-free counterpart of {@see ConfiguresInstanceProxy} for the in-process
 * Facade/Client path.
 *
 * Exposes {@see configureProxy()} returning a plain array so host apps can
 * configure (or, with an empty/null URL, disable) the proxy of an instance
 * through the SDK Facade — without mounting the gateway HTTP routes.
 *
 * The per-provider upstream contract differs (URL, auth, body, enable/disable
 * semantics), so each Client implements {@see sendProxyConfig()}; failure
 * detection, token redaction and error handling live here.
 */
trait ConfiguresClientProxy
{
    /**
     * Max seconds to wait on the upstream proxy-config call.
     *
     * `protected` (not `private`) so Client subclasses that override
     * {@see sendProxyConfig()} — e.g. FunapiClient extends ZApiClient — can read
     * it; a `private` trait const is scoped to the using class (ZApiClient) and is
     * invisible to its children, which would fatal with "Undefined constant".
     */
    protected const PROXY_CONFIG_TIMEOUT = 10;

    /** Fallback error text when the upstream returns no error detail. */
    protected const UNKNOWN_PROXY_ERROR = 'Unknown error';

    /**
     * Configure (enable) or disable the proxy of an existing instance.
     *
     * A non-empty $proxyUrl enables it; an empty/null value disables and clears
     * it. Never throws: failures are logged and surfaced via the returned array
     * so the caller can react (e.g. alert) instead of the proxy failing silently.
     *
     * @return array{proxy_configured: bool, error?: string}
     */
    public function configureProxy(string $uid, string $token, ?string $proxyUrl): array
    {
        try {
            $response = $this->sendProxyConfig($uid, $token, $proxyUrl);
            if ($this->proxyConfigFailed($response)) {
                $error = $response->json('error') ?? self::UNKNOWN_PROXY_ERROR;
                logger()->error('deviceUid #' . $uid . ' configureProxy error response', [
                    'uid' => $uid,
                    'error' => $error,
                ]);

                return ['proxy_configured' => false, 'error' => $error];
            }

            return ['proxy_configured' => true];
        } catch (ConnectionException $e) {
            $error = $this->redactProxySecrets($e->getMessage(), $token, $proxyUrl);
            logger()->error('deviceUid #' . $uid . ' configureProxy failed (ConnectionException)', ['uid' => $uid, 'error' => $error]);

            return ['proxy_configured' => false, 'error' => $error];
        } catch (\Throwable $e) {
            $error = $this->redactProxySecrets($e->getMessage(), $token, $proxyUrl);
            logger()->error('deviceUid #' . $uid . ' configureProxy failed (Throwable)', ['uid' => $uid, 'error' => $error]);

            return ['proxy_configured' => false, 'error' => $error];
        }
    }

    private function proxyConfigFailed(ClientResponse $response): bool
    {
        return $response->failed() || $response->json('error');
    }

    /**
     * Strip secrets from upstream error messages before logging/returning them:
     * the instance token and any credentials embedded in the proxy URL
     * (`scheme://user:pass@host` — the user:pass userinfo is replaced).
     */
    private function redactProxySecrets(string $message, ?string $token, ?string $proxyUrl): string
    {
        if (!empty($token)) {
            $message = str_replace($token, '***', $message);
        }

        if (!empty($proxyUrl)) {
            $message = str_replace($proxyUrl, $this->redactUrlUserInfo($proxyUrl), $message);
        }

        return $message;
    }

    /** Replace the `user:pass@` userinfo of a proxy URL with `***@`, leaving the host intact. */
    private function redactUrlUserInfo(string $url): string
    {
        return preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@]+@#i', '$1***@', $url);
    }

    /**
     * Perform the provider-specific proxy configuration request. A non-empty
     * $proxyUrl enables the proxy; an empty/null value disables and clears it.
     * $uid is passed verbatim — each provider normalizes it as needed (Z-API
     * strips a `-` suffix; Funapi keeps the full UUID).
     */
    abstract protected function sendProxyConfig(string $uid, string $token, ?string $proxyUrl): ClientResponse;
}
