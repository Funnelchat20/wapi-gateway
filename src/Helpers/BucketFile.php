<?php

namespace Funnelchat\WapiGateway\Helpers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Single place where a bucket object key becomes a URL, and where its bytes
 * are read.
 *
 * ## Why the key must be percent-encoded
 *
 * Keys are concatenated onto `aws_bucket_url` and fetched over plain HTTP, so
 * every character in them is read by the URL parser, not by S3. `#` starts a
 * fragment and `?` starts a query: a real key `promo#2.jpg` was requested as
 * `/promo` — a different object, or none — and the old `@file_get_contents()`
 * swallowed that silently. Those characters are NOT hypothetical: the bucket
 * already holds keys with `+ & ' , @ [ ] ! ~ = #` (they are accepted upstream
 * precisely because they are legitimate, already-stored keys).
 *
 * Each `/`-separated segment is `rawurlencode`d, which keeps the path structure
 * intact and encodes everything else per RFC 3986. S3 percent-decodes the path
 * before matching the key, so the object that comes back is the one named.
 *
 * ## Contract with callers
 *
 * `$fileKey` is always the RAW key — exactly as stored — never pre-encoded.
 * Encoding an already-encoded key would turn `%23` into `%2523` and miss. This
 * repo defines the contract; `conversations` builds the same URL on its side
 * and must hand over raw keys too.
 *
 * ## Why a timeout
 *
 * The download sits on the `media_id` path, which runs once per broadcast page.
 * `@file_get_contents()` had no time limit at all, so a slow or silent S3 held
 * the job until the lambda killed it — taking the whole page, not one send.
 *
 * ## Why the two failure messages differ
 *
 * The caller retries transient failures and gives up on permanent ones. A
 * definitive "the object is not readable" answer (404/403/410 — this bucket
 * answers 403 for a missing object because `ListBucket` is denied) returns
 * `File not found`, which the consumer already classifies as permanent.
 * Anything else — timeout, connection reset, 5xx — returns
 * `File download failed: …`, which stays retryable, and never gets confused
 * with a rejection coming from Meta.
 */
final class BucketFile
{
    /**
     * Seconds allowed for the whole download. Generous enough for a 100 MB
     * document (the largest WhatsApp Cloud accepts) on a slow link, far below
     * the lambda budget it used to consume entirely.
     */
    private const DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * Seconds allowed to establish the connection. A bucket that does not
     * answer at all should fail fast rather than eat the full budget.
     */
    private const DEFAULT_CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * Statuses that mean "this object is not readable" rather than "the bucket
     * did not answer". 403 is in the list because a missing object on this
     * bucket answers 403, not 404.
     *
     * @var array<int>
     */
    private const NOT_FOUND_STATUSES = [403, 404, 410];

    /**
     * Build the public URL for a raw bucket key.
     */
    public static function url(string $fileKey): string
    {
        $base = rtrim((string) config('wapi-gateway.aws_bucket_url'), '/');

        $path = implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', ltrim($fileKey, '/')),
        ));

        return $base . '/' . $path;
    }

    /**
     * Download a bucket object.
     *
     * @return array{content: string}|array{error: string, error_source: string}
     */
    public static function fetch(string $fileKey): array
    {
        $url = self::url($fileKey);

        try {
            $response = Http::timeout(config('wapi-gateway.media_download_timeout', self::DEFAULT_TIMEOUT_SECONDS))
                ->connectTimeout(config('wapi-gateway.media_download_connect_timeout', self::DEFAULT_CONNECT_TIMEOUT_SECONDS))
                ->get($url);
        } catch (ConnectionException $e) {
            return self::downloadFailed('connection: ' . $e->getMessage());
        }

        if (in_array($response->status(), self::NOT_FOUND_STATUSES, true)) {
            return ['error' => 'File not found', 'error_source' => 'download'];
        }

        if ($response->failed()) {
            return self::downloadFailed('HTTP ' . $response->status());
        }

        $content = $response->body();

        // A 200 with an empty body is not a usable file: uploading zero bytes to
        // Meta trades a clear failure here for an opaque one there.
        if ($content === '') {
            return self::downloadFailed('empty body');
        }

        return ['content' => $content];
    }

    /**
     * @return array{error: string, error_source: string}
     */
    private static function downloadFailed(string $reason): array
    {
        return ['error' => 'File download failed: ' . $reason, 'error_source' => 'download'];
    }
}
