<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * `MetaClient::uploadMedia()` — the door to the `media_id` path, which runs once
 * per broadcast page (issue #63).
 *
 * What matters to the caller is that the two failure sources stay apart: it
 * retries a download failure and gives up on a Meta rejection.
 */
class MetaClientUploadMediaTest extends TestCase
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

    public function test_it_downloads_the_key_percent_encoded_and_uploads_the_bytes(): void
    {
        Http::fake([
            'bucket.example.com/*' => Http::response('the-bytes', 200),
            'graph.facebook.com/*' => Http::response(['id' => '1234'], 200),
        ]);

        $result = (new MetaClient())->uploadMedia('uid', 'token', '42/promo#2.jpg', 'image/jpeg');

        $this->assertSame('1234', $result['id']);
        Http::assertSent(fn ($request) => $request->url() === 'https://bucket.example.com/42/promo%232.jpg');
    }

    public function test_a_missing_object_is_reported_as_a_permanent_file_not_found_and_never_reaches_meta(): void
    {
        Http::fake([
            'bucket.example.com/*' => Http::response('', 403),
            'graph.facebook.com/*' => Http::response(['id' => '1234'], 200),
        ]);

        $result = (new MetaClient())->uploadMedia('uid', 'token', '42/gone.jpg', 'image/jpeg');

        $this->assertSame('File not found', $result['error']);
        $this->assertSame('download', $result['error_source']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_a_slow_bucket_fails_with_a_retryable_download_error_instead_of_hanging(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'bucket.example.com')) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['id' => '1234'], 200);
        });

        $result = (new MetaClient())->uploadMedia('uid', 'token', '42/a.jpg', 'image/jpeg');

        $this->assertStringStartsWith('File download failed:', $result['error']);
        $this->assertSame('download', $result['error_source']);
        // Not "file not found": the caller must still retry this one.
        $this->assertStringNotContainsStringIgnoringCase('file not found', $result['error']);
    }

    /**
     * A rejection from Meta carries Meta's own payload and no `error_source`, so
     * the caller can tell it apart from anything that happened before the upload.
     */
    public function test_a_meta_rejection_keeps_metas_error_payload_and_is_not_marked_as_a_download_failure(): void
    {
        Http::fake([
            'bucket.example.com/*' => Http::response('the-bytes', 200),
            'graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid token']], 400),
        ]);

        $result = (new MetaClient())->uploadMedia('uid', 'token', '42/a.jpg', 'image/jpeg');

        $this->assertSame(190, $result['error']['code']);
        $this->assertArrayNotHasKey('error_source', $result);
    }
}
