<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Clients\UazapiClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\ZApiLiteClient;
use Funnelchat\WapiGateway\Jobs\StoreDeviceLogJob;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A signed media URL reaches the provider whole, signature included, and
 * reaches neither the device_logs row (StoreDeviceLogJob) nor the log line
 * with its query string. Pinned per client, on the key each one carries the
 * media URL under.
 */
class DeviceLogSignedUrlTest extends TestCase
{
    private const PHONE = '5491100000000';

    private const SIGNED = 'https://b.s3.amazonaws.com/a/photo.jpg?X-Amz-Expires=300&X-Amz-Signature=abc';

    private const STRIPPED = 'https://b.s3.amazonaws.com/a/photo.jpg';

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wapi-gateway.logging_enabled' => true,
            'zapi.base_url' => 'https://zapi.example.com',
            'zapi.client_token' => 'zapi-client-token',
            'zapi-lite.base_url' => 'https://zapilite.example.com',
            'zapi-lite.client_token' => 'zapilite-client-token',
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);
        config(['uazapi' => require dirname(__DIR__, 3) . '/config/uazapi.php']);
        config(['uazapi.base_url' => 'https://uazapi.example.com']);

        Queue::fake();
        config(['logging.default' => 'null']);
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event;
        });
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
            // UAZAPI echoes the media URL back; the response body is logged too.
            'https://uazapi.example.com/*' => Http::response(['id' => 'MSG-1', 'fileURL' => self::SIGNED], 200),
            '*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1', 'id' => 'MSG-1'], 200),
        ]);
    }

    private function prop(StoreDeviceLogJob $job, string $name): mixed
    {
        return (new \ReflectionProperty($job, $name))->getValue($job);
    }

    private function assertLoggedWithoutSignature(callable $payloadHasStrippedUrl): void
    {
        $jobs = Queue::pushed(StoreDeviceLogJob::class);
        $this->assertCount(1, $jobs);
        $job = $jobs->first();

        $this->assertTrue($payloadHasStrippedUrl($this->prop($job, 'requestPayload')), 'The device log keeps the URL, without its query.');
        foreach (['url', 'requestPayload', 'responseBody'] as $name) {
            $this->assertStringNotContainsString('X-Amz-Signature', json_encode($this->prop($job, $name)), "{$name} must not hold the signature.");
        }

        $this->assertNotEmpty($this->logged, 'The send writes a log line.');
        foreach ($this->logged as $event) {
            $this->assertStringNotContainsString('X-Amz-Signature', $event->message . json_encode($event->context), 'A log line must not hold the signature.');
        }
    }

    public static function sendFileProvider(): array
    {
        return [
            'Meta' => [
                fn () => (new MetaClient())->sendFile('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, self::SIGNED),
                fn (array $data) => ($data['image']['link'] ?? null) === self::SIGNED,
                fn (array $payload) => ($payload['image']['link'] ?? null) === self::STRIPPED,
            ],
            'Meta button header' => [
                fn () => (new MetaClient())->sendButtons('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, 'Elegí', [['id' => 'a', 'label' => 'A']], ['fileUrl' => self::SIGNED]),
                fn (array $data) => ($data['interactive']['header']['image']['link'] ?? null) === self::SIGNED,
                fn (array $payload) => ($payload['interactive']['header']['image']['link'] ?? null) === self::STRIPPED,
            ],
            'Z-API' => [
                fn () => (new ZApiClient())->sendFile('UID', 'TOKEN', self::PHONE, self::SIGNED),
                fn (array $data) => ($data['image'] ?? null) === self::SIGNED,
                fn (array $payload) => ($payload['image'] ?? null) === self::STRIPPED,
            ],
            'Z-API Lite' => [
                fn () => (new ZApiLiteClient())->sendFile('UID', 'TOKEN', self::PHONE, self::SIGNED),
                fn (array $data) => ($data['image'] ?? null) === self::SIGNED,
                fn (array $payload) => ($payload['image'] ?? null) === self::STRIPPED,
            ],
            'FunApi' => [
                fn () => (new FunapiClient())->sendFile('UID', 'TOKEN', self::PHONE, self::SIGNED),
                fn (array $data) => ($data['image'] ?? null) === self::SIGNED,
                fn (array $payload) => ($payload['image'] ?? null) === self::STRIPPED,
            ],
            'UAZAPI' => [
                fn () => (new UazapiClient())->sendFile('UID', 'TOKEN', self::PHONE, self::SIGNED),
                fn (array $data) => ($data['file'] ?? null) === self::SIGNED,
                fn (array $payload) => ($payload['file'] ?? null) === self::STRIPPED,
            ],
        ];
    }

    #[DataProvider('sendFileProvider')]
    public function test_the_provider_gets_the_signed_url_and_the_logs_do_not(callable $send, callable $sentHasSignedUrl, callable $loggedHasStrippedUrl): void
    {
        $result = $send();

        $this->assertArrayNotHasKey('error', $result);
        Http::assertSent(fn (Request $request) => $sentHasSignedUrl($request->data()));
        $this->assertLoggedWithoutSignature($loggedHasStrippedUrl);
    }

    /** The token in a Z-API endpoint stays masked, as before. */
    public function test_the_zapi_endpoint_token_is_still_masked(): void
    {
        (new ZApiClient())->sendFile('UID', 'SECRET-INSTANCE-TOKEN', self::PHONE, self::SIGNED);

        $url = $this->prop(Queue::pushed(StoreDeviceLogJob::class)->first(), 'url');
        $this->assertSame('https://zapi.example.com/instances/UID/token/***/send-image', $url);
    }
}
