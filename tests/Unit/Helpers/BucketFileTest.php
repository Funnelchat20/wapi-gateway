<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Helpers;

use Funnelchat\WapiGateway\Helpers\BucketFile;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * `BucketFile` — the single door to bucket objects (issue #63).
 *
 * Two behaviours are pinned here because both were silently broken: the key was
 * concatenated raw (so `#` truncated the URL at the fragment and the wrong
 * object, or none, came back) and the read had no timeout at all.
 */
class BucketFileTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['wapi-gateway.aws_bucket_url' => 'https://bucket.example.com']);
    }

    public function test_url_percent_encodes_the_key_but_keeps_the_path_separators(): void
    {
        $this->assertSame(
            'https://bucket.example.com/42/promo%232.jpg',
            BucketFile::url('42/promo#2.jpg'),
        );

        $this->assertSame(
            'https://bucket.example.com/42/a%3Fb.pdf',
            BucketFile::url('42/a?b.pdf'),
        );
    }

    /**
     * Every character the upstream validator accepts as a legitimate, already
     * stored key must survive the round trip: encode, then decode, gives back
     * exactly what was passed in.
     */
    public function test_url_round_trips_every_character_real_keys_contain(): void
    {
        $key = "7/pro+mo & 'a', b@c [d] e! f~g =h #2.jpg";

        $url = BucketFile::url($key);

        $this->assertStringStartsWith('https://bucket.example.com/', $url);
        $this->assertSame(
            $key,
            rawurldecode(substr($url, strlen('https://bucket.example.com/'))),
        );
    }

    public function test_url_tolerates_a_trailing_slash_on_the_base_and_a_leading_slash_on_the_key(): void
    {
        config(['wapi-gateway.aws_bucket_url' => 'https://bucket.example.com/']);

        $this->assertSame('https://bucket.example.com/42/a.jpg', BucketFile::url('/42/a.jpg'));
    }

    public function test_fetch_returns_the_body_and_requests_the_encoded_url(): void
    {
        Http::fake([
            'bucket.example.com/*' => Http::response('bytes', 200),
        ]);

        $this->assertSame(['content' => 'bytes'], BucketFile::fetch('42/promo#2.jpg'));

        Http::assertSent(fn ($request) => $request->url() === 'https://bucket.example.com/42/promo%232.jpg');
    }

    /**
     * A missing object on this bucket answers 403 (ListBucket is denied), not
     * 404 — both have to read as permanent so the caller stops retrying.
     */
    public function test_fetch_reports_a_missing_object_as_file_not_found(): void
    {
        foreach ([403, 404, 410] as $status) {
            Http::fake(['bucket.example.com/*' => Http::response('', $status)]);

            $result = BucketFile::fetch('42/gone.jpg');

            $this->assertSame('File not found', $result['error'], "status {$status}");
            $this->assertSame('download', $result['error_source']);
        }
    }

    public function test_fetch_reports_a_server_error_as_a_retryable_download_failure(): void
    {
        Http::fake(['bucket.example.com/*' => Http::response('', 500)]);

        $result = BucketFile::fetch('42/a.jpg');

        $this->assertSame('File download failed: HTTP 500', $result['error']);
        $this->assertSame('download', $result['error_source']);
        $this->assertStringNotContainsStringIgnoringCase('file not found', $result['error']);
    }

    public function test_fetch_reports_a_timeout_as_a_retryable_download_failure_instead_of_hanging(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $result = BucketFile::fetch('42/a.jpg');

        $this->assertStringStartsWith('File download failed: connection:', $result['error']);
        $this->assertSame('download', $result['error_source']);
    }

    public function test_fetch_rejects_an_empty_body_rather_than_uploading_zero_bytes(): void
    {
        Http::fake(['bucket.example.com/*' => Http::response('', 200)]);

        $this->assertSame('File download failed: empty body', BucketFile::fetch('42/a.jpg')['error']);
    }
}
