<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Jobs\StoreDeviceLogJob;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;

/**
 * `unsubscribe()` used to make its two HTTP calls without logging, so a failed
 * unsubscribe left no device-log trace — the record a caller needs to find a paid
 * instance that was billed but never cancelled. These tests pin that it now logs
 * like the rest of the client, and that the token is never persisted.
 */
class ZApiClientUnsubscribeTest extends TestCase
{
    private ZApiClient $client;

    private const TOKEN = 'SECRET-TOKEN-123';
    private const DISCONNECT_URL = 'https://api.z-api.io/instances/*/token/*/disconnect';
    private const CANCEL_URL = 'https://api.z-api.io/instances/*/token/*/integrator/on-demand/cancel';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'zapi.base_url' => 'https://api.z-api.io',
            'zapi.client_token' => 'client-token',
            'zapi.token' => 'integrator-token',
            'zapi.unsubscription_url' => 'https://api.z-api.io/instances/UID/token/TOKEN/integrator/on-demand/cancel',
            'wapi-gateway.logging_enabled' => true,
        ]);

        $this->client = new ZApiClient();
    }

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function prop(StoreDeviceLogJob $job, string $name): mixed
    {
        $p = (new \ReflectionObject($job))->getProperty($name);
        $p->setAccessible(true);
        return $p->getValue($job);
    }

    public function test_failed_cancel_is_recorded_as_an_errored_unsubscribe_device_log(): void
    {
        Queue::fake();
        Http::fake([
            self::DISCONNECT_URL => Http::response([], 200),
            self::CANCEL_URL => Http::response(['error' => 'subscription_not_found'], 400),
        ]);

        $result = $this->client->unsubscribe('INST123', self::TOKEN);

        $this->assertSame('subscription_not_found', $result['error']);

        // The cancel step must produce an errored unsubscribe row.
        Queue::assertPushed(StoreDeviceLogJob::class, function (StoreDeviceLogJob $job) {
            return $this->prop($job, 'method') === 'unsubscribe'
                && $this->prop($job, 'provider') === 'zapi'
                && ($this->prop($job, 'requestPayload')['step'] ?? null) === 'cancel'
                && $this->prop($job, 'isError') === true;
        });
    }

    public function test_failed_disconnect_is_recorded_and_short_circuits_before_cancel(): void
    {
        Queue::fake();
        Http::fake([
            self::DISCONNECT_URL => Http::response(['error' => 'instance_offline'], 400),
            self::CANCEL_URL => Http::response(['due' => 1700000000000], 200),
        ]);

        $result = $this->client->unsubscribe('INST123', self::TOKEN);

        $this->assertSame('instance_offline', $result['error']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/integrator/on-demand/cancel'));

        Queue::assertPushed(StoreDeviceLogJob::class, 1);
        Queue::assertPushed(StoreDeviceLogJob::class, function (StoreDeviceLogJob $job) {
            return $this->prop($job, 'method') === 'unsubscribe'
                && ($this->prop($job, 'requestPayload')['step'] ?? null) === 'disconnect'
                && $this->prop($job, 'isError') === true;
        });
    }

    public function test_the_real_token_is_never_persisted_in_any_unsubscribe_log(): void
    {
        Queue::fake();
        Http::fake([
            self::DISCONNECT_URL => Http::response([], 200),
            self::CANCEL_URL => Http::response(['error' => 'boom'], 500),
        ]);

        $this->client->unsubscribe('INST123', self::TOKEN);

        Queue::assertPushed(StoreDeviceLogJob::class, function (StoreDeviceLogJob $job) {
            $url = $this->prop($job, 'url');
            $this->assertStringContainsString('/token/***/', $url, 'URL must be masked.');
            $this->assertStringNotContainsString(self::TOKEN, $url, 'Raw token must never reach the device log URL.');
            return true;
        });
    }

    public function test_successful_unsubscribe_logs_both_steps(): void
    {
        Queue::fake();
        Http::fake([
            self::DISCONNECT_URL => Http::response([], 200),
            self::CANCEL_URL => Http::response(['due' => 1700000000000], 200),
        ]);

        $result = $this->client->unsubscribe('INST123', self::TOKEN);

        $this->assertArrayHasKey('paidTill', $result);
        // Parity with the rest of the client: both calls are logged (as success).
        Queue::assertPushed(StoreDeviceLogJob::class, 2);
        Queue::assertPushed(StoreDeviceLogJob::class, fn (StoreDeviceLogJob $job) => $this->prop($job, 'isError') === false
            && $this->prop($job, 'method') === 'unsubscribe');
    }
}
