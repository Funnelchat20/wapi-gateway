<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\ZApiLiteClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * `unsubscribe()` = disconnect (courtesy logout) + cancel (stop the on-demand subscription,
 * i.e. stop paying). A failed disconnect — most commonly an instance already disconnected on
 * the provider — must NOT skip the cancel: otherwise the paid subscription is never cancelled
 * for exactly the population a teardown targets (disconnected instances).
 */
class UnsubscribeCancelsEvenIfDisconnectFailsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function configureZApi(): void
    {
        config([
            'zapi.base_url' => 'https://zapi.example.com',
            'zapi.client_token' => 'zapi-client-token',
            'zapi.token' => 'zapi-token',
            'zapi.unsubscription_url' => 'https://zapi.example.com/instances/UID/token/TOKEN/integrator/on-demand/cancel',
        ]);
    }

    public function test_zapi_still_cancels_when_the_disconnect_fails(): void
    {
        $this->configureZApi();

        Http::fake([
            '*integrator/on-demand/cancel' => Http::response(['due' => 1_700_000_000_000], 200),
            '*/disconnect' => Http::response(['error' => 'instance already disconnected'], 400),
        ]);

        $result = (new ZApiClient())->unsubscribe('UID', 'TOKEN');

        // The cancel ran and succeeded despite the failed disconnect.
        $this->assertArrayNotHasKey('error', $result);
        $this->assertArrayHasKey('paidTill', $result);

        // Both calls were attempted, in particular the cancel.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'integrator/on-demand/cancel'));
    }

    public function test_zapi_lite_inherits_the_same_behavior(): void
    {
        config([
            'zapi-lite.base_url' => 'https://lite.z-api.io',
            'zapi-lite.client_token' => 'lite-client-token',
            'zapi-lite.token' => 'lite-token',
            'zapi-lite.unsubscription_url' => 'https://lite.z-api.io/instances/UID/token/TOKEN/integrator/on-demand/cancel',
        ]);

        Http::fake([
            '*integrator/on-demand/cancel' => Http::response(['due' => 1_700_000_000_000], 200),
            '*/disconnect' => Http::response(['error' => 'instance already disconnected'], 400),
        ]);

        $result = (new ZApiLiteClient())->unsubscribe('UID', 'TOKEN');

        $this->assertArrayNotHasKey('error', $result);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'integrator/on-demand/cancel'));
    }

    public function test_funapi_still_cancels_when_the_disconnect_fails(): void
    {
        config([
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
            'funapi.token' => 'funapi-token',
            'funapi.unsubscription_url' => 'https://funapi.example.com/instances/UID/token/TOKEN/integrator/on-demand/cancel',
        ]);

        Http::fake([
            '*integrator/on-demand/cancel' => Http::response(['due' => 1_700_000_000_000], 200),
            '*/disconnect' => Http::response(['error' => 'instance already disconnected'], 400),
        ]);

        $result = (new FunapiClient())->unsubscribe('UID', 'TOKEN');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertArrayHasKey('paidTill', $result);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'integrator/on-demand/cancel'));
    }

    /** A genuine cancel failure must still surface as an error (unchanged behavior). */
    public function test_zapi_still_reports_an_error_when_the_cancel_itself_fails(): void
    {
        $this->configureZApi();

        Http::fake([
            '*integrator/on-demand/cancel' => Http::response(['error' => 'cancel boom'], 500),
            '*/disconnect' => Http::response(['error' => 'instance already disconnected'], 400),
        ]);

        $result = (new ZApiClient())->unsubscribe('UID', 'TOKEN');

        $this->assertSame('cancel boom', $result['error'] ?? null);
    }
}
