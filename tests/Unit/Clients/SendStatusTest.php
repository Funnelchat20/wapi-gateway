<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\ZApiLiteClient;
use Funnelchat\WapiGateway\Jobs\StoreDeviceLogJob;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * WhatsApp statuses (the 24h "estado"), not the instance connection `status()`.
 *
 * A status is published from the instance's own number and has no recipient,
 * so the payload carries no `phone` at all — the exact payload is pinned, not
 * just the presence of the right key.
 *
 * Z-API and FunApi publish them. FunapiClient inherits the image and video
 * methods and overrides only the text one, because the bridge reads `text`
 * where Z-API reads `message`; its exact payloads are pinned too.
 * ZApiLiteClient also extends ZApiClient, so without its own overrides it
 * would inherit the methods and post the status to its own host. Its refusal
 * is pinned with `assertNothingSent()`, which is exactly what inheritance
 * breaks.
 */
class SendStatusTest extends TestCase
{
    private const IMAGE = 'https://cdn.example.com/promo.jpg';

    private const VIDEO = 'https://cdn.example.com/lanzamiento.mp4';

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'zapi.base_url' => 'https://zapi.example.com',
            'zapi.client_token' => 'zapi-client-token',
            'zapi-lite.base_url' => 'https://zapilite.example.com',
            'zapi-lite.client_token' => 'zapilite-client-token',
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);
    }

    private function fakeOk(): void
    {
        Http::fake([
            '*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1'], 200),
        ]);
    }

    private function prop(StoreDeviceLogJob $job, string $name): mixed
    {
        $p = (new \ReflectionObject($job))->getProperty($name);
        $p->setAccessible(true);
        return $p->getValue($job);
    }

    /**
     * @return array<string, array{0: string, 1: callable, 2: string, 3: array}>
     */
    public static function statuses(): array
    {
        return [
            'text' => [
                'sendTextStatus',
                fn(ZApiClient $client) => $client->sendTextStatus('UID', 'TOKEN', 'Hoy abrimos hasta las 20 h'),
                'send-text-status',
                ['message' => 'Hoy abrimos hasta las 20 h'],
            ],
            'image' => [
                'sendImageStatus',
                fn(ZApiClient $client) => $client->sendImageStatus('UID', 'TOKEN', self::IMAGE, 'Nueva colección'),
                'send-image-status',
                ['image' => self::IMAGE, 'caption' => 'Nueva colección'],
            ],
            'video' => [
                'sendVideoStatus',
                fn(ZApiClient $client) => $client->sendVideoStatus('UID', 'TOKEN', self::VIDEO, 'Mirá el lanzamiento'),
                'send-video-status',
                ['video' => self::VIDEO, 'caption' => 'Mirá el lanzamiento'],
            ],
        ];
    }

    /**
     * @return array<string, array{0: callable, 1: string, 2: array}>
     */
    public static function funapiStatuses(): array
    {
        return [
            'text' => [
                fn(FunapiClient $client) => $client->sendTextStatus('UID', 'TOKEN', 'Hoy abrimos hasta las 20 h'),
                'send-text-status',
                ['text' => 'Hoy abrimos hasta las 20 h'],
            ],
            'image' => [
                fn(FunapiClient $client) => $client->sendImageStatus('UID', 'TOKEN', self::IMAGE, 'Nueva colección'),
                'send-image-status',
                ['image' => self::IMAGE, 'caption' => 'Nueva colección'],
            ],
            'video' => [
                fn(FunapiClient $client) => $client->sendVideoStatus('UID', 'TOKEN', self::VIDEO, 'Mirá el lanzamiento'),
                'send-video-status',
                ['video' => self::VIDEO, 'caption' => 'Mirá el lanzamiento'],
            ],
        ];
    }

    /**
     * Every status method that does call out: Z-API's three and FunApi's.
     *
     * @return array<string, array{0: callable}>
     */
    public static function publishers(): array
    {
        $cases = [];
        foreach (self::statuses() as $kind => [, $publish]) {
            $cases["Z-API $kind"] = [fn() => $publish(new ZApiClient())];
        }
        foreach (self::funapiStatuses() as $kind => [$publish]) {
            $cases["FunApi $kind"] = [fn() => $publish(new FunapiClient())];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: callable, 1: array}>
     */
    public static function captionlessMedia(): array
    {
        return [
            'image, null caption' => [fn(ZApiClient $client) => $client->sendImageStatus('UID', 'TOKEN', self::IMAGE), ['image' => self::IMAGE]],
            'image, blank caption' => [fn(ZApiClient $client) => $client->sendImageStatus('UID', 'TOKEN', self::IMAGE, '   '), ['image' => self::IMAGE]],
            'video, null caption' => [fn(ZApiClient $client) => $client->sendVideoStatus('UID', 'TOKEN', self::VIDEO), ['video' => self::VIDEO]],
            'video, blank caption' => [fn(ZApiClient $client) => $client->sendVideoStatus('UID', 'TOKEN', self::VIDEO, ''), ['video' => self::VIDEO]],
        ];
    }

    /**
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function refusals(): array
    {
        return [
            'ZApiLite text' => [fn() => (new ZApiLiteClient())->sendTextStatus('UID', 'TOKEN', 'Hola'), 'sendTextStatus is not supported by ZApiLite provider'],
            'ZApiLite image' => [fn() => (new ZApiLiteClient())->sendImageStatus('UID', 'TOKEN', self::IMAGE, 'Hola'), 'sendImageStatus is not supported by ZApiLite provider'],
            'ZApiLite video' => [fn() => (new ZApiLiteClient())->sendVideoStatus('UID', 'TOKEN', self::VIDEO, 'Hola'), 'sendVideoStatus is not supported by ZApiLite provider'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_it_posts_only_the_status_payload_to_its_endpoint(string $method, callable $publish, string $action, array $payload): void
    {
        $this->fakeOk();

        $publish(new ZApiClient());

        Http::assertSentCount(1);
        Http::assertSent(fn($request) => $request->method() === 'POST'
            && $request->url() === "https://zapi.example.com/instances/UID/token/TOKEN/$action"
            && $request->hasHeader('Client-Token', 'zapi-client-token')
            && $request->data() === $payload);
    }

    /**
     * The bridge serves Z-API's paths on its own host, with its own
     * Client-Token. Text goes out as `text`, the field it reads.
     */
    #[DataProvider('funapiStatuses')]
    public function test_funapi_posts_the_payload_the_bridge_reads(callable $publish, string $action, array $payload): void
    {
        $this->fakeOk();

        $result = $publish(new FunapiClient());

        Http::assertSentCount(1);
        Http::assertSent(fn($request) => $request->method() === 'POST'
            && $request->url() === "https://funapi.example.com/instances/UID/token/TOKEN/$action"
            && $request->hasHeader('Client-Token', 'funapi-client-token')
            && $request->data() === $payload);
        $this->assertTrue($result['sent']);
        $this->assertSame('MSG-1', $result['id']);
    }

    #[DataProvider('statuses')]
    public function test_a_published_status_returns_the_normalized_send_envelope(string $method, callable $publish): void
    {
        $this->fakeOk();

        $result = $publish(new ZApiClient());

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue($result['sent']);
        $this->assertSame('MSG-1', $result['id']);
        $this->assertSame('ZAAP-1', $result['queueId']);
    }

    /** Same as sendText: a 200 without `messageId` is not an error, just not `sent`. */
    public function test_a_response_without_message_id_is_not_reported_as_sent(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $result = (new ZApiClient())->sendTextStatus('UID', 'TOKEN', 'Hola');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertFalse($result['sent']);
        $this->assertSame('', $result['id']);
    }

    /** Caption is optional on Z-API: a missing one is omitted, never sent empty. */
    #[DataProvider('captionlessMedia')]
    public function test_a_null_or_blank_caption_is_omitted(callable $publish, array $payload): void
    {
        $this->fakeOk();

        $publish(new ZApiClient());

        Http::assertSent(fn($request) => $request->data() === $payload);
    }

    #[DataProvider('statuses')]
    public function test_a_provider_error_comes_back_classified(string $method, callable $publish): void
    {
        Http::fake(['*' => Http::response(['error' => 'You need to be connected with whatsapp'], 200)]);

        $this->assertSame(['error' => 'disconnected_device'], $publish(new ZApiClient()));
    }

    #[DataProvider('statuses')]
    public function test_an_http_failure_is_reported_as_an_error_not_as_published(string $method, callable $publish): void
    {
        Http::fake(['*' => Http::response(['error' => 'Instance not found'], 404)]);

        $this->assertSame(['error' => 'instance_not_found'], $publish(new ZApiClient()));
    }

    #[DataProvider('statuses')]
    public function test_a_failed_publish_is_recorded_as_an_errored_device_log(string $method, callable $publish): void
    {
        config(['wapi-gateway.logging_enabled' => true]);
        Queue::fake();
        Http::fake(['*' => Http::response(['error' => 'Instance not found'], 404)]);

        $publish(new ZApiClient());

        Queue::assertPushed(StoreDeviceLogJob::class, 1);
        Queue::assertPushed(StoreDeviceLogJob::class, fn(StoreDeviceLogJob $job) => $this->prop($job, 'method') === $method
            && $this->prop($job, 'provider') === 'zapi'
            && $this->prop($job, 'isError') === true
            && ! str_contains($this->prop($job, 'url'), '/token/TOKEN/'));
    }

    public function test_a_published_status_is_recorded_as_a_successful_device_log(): void
    {
        config(['wapi-gateway.logging_enabled' => true]);
        Queue::fake();
        $this->fakeOk();

        (new ZApiClient())->sendTextStatus('UID', 'TOKEN', 'Hola');

        Queue::assertPushed(StoreDeviceLogJob::class, fn(StoreDeviceLogJob $job) => $this->prop($job, 'method') === 'sendTextStatus'
            && $this->prop($job, 'isError') === false);
    }

    /**
     * A failed publish is not replayed: the request may have landed anyway,
     * and a second POST would publish the status twice.
     */
    #[DataProvider('publishers')]
    public function test_a_failed_publish_is_not_retried(callable $publish): void
    {
        Http::fakeSequence()
            ->push(['error' => 'Whatsapp did not respond'], 500)
            ->push(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1'], 200);

        $this->assertSame(['error' => 'not_respond'], $publish());
        Http::assertSentCount(1);
    }

    /** Same timeout as sendText: `$options['timeout']`, else `zapi.timeout`. */
    public function test_it_uses_the_send_text_timeout(): void
    {
        config(['zapi.timeout' => '45']);
        $timeouts = [];
        Http::fake(function ($request, array $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'] ?? null;

            return Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1'], 200);
        });

        $client = new ZApiClient();
        $client->sendTextStatus('UID', 'TOKEN', 'Hola');
        $client->sendVideoStatus('UID', 'TOKEN', self::VIDEO, null, ['timeout' => 120]);

        $this->assertSame([45, 120], $timeouts);
    }

    /** The host would answer 200: an inherited method would come back as published. */
    #[DataProvider('refusals')]
    public function test_zapi_lite_refuses_without_calling_out(callable $publish, string $error): void
    {
        $this->fakeOk();

        $this->assertSame(['error' => $error], $publish());
        Http::assertNothingSent();
    }
}
