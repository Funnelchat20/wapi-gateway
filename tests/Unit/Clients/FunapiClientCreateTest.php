<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class FunapiClientCreateTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function fakeFunapi(): void
    {
        config([
            'funapi.on_demand_url' => 'https://funapi.example.com/instances/on-demand',
            'funapi.subscription_url' => 'https://funapi.example.com/instances/UID/token/TOKEN/subscribe',
            'funapi.token' => 'funapi-token',
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
            // Leave webhooks unset to keep the create payload minimal.
            'funapi.webhook_base_url' => null,
        ]);

        // Catch-all: the create POST (on_demand_url) plus the follow-up subscribe
        // call both hit funapi.example.com. Returning id+token lets create proceed.
        Http::fake([
            'https://funapi.example.com/*' => Http::response(['id' => 'UID-1', 'token' => 'TOKEN-1'], 200),
        ]);
    }

    public function test_create_sends_uppercased_country_code_when_provided(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->create(7, 42, 'co');

        // The on-demand create POST (sent before the subscribe step) must carry
        // the country hint, normalized to uppercase ISO alpha-2.
        Http::assertSent(function ($request) {
            return $request->url() === 'https://funapi.example.com/instances/on-demand'
                && $request->method() === 'POST'
                && ($request->data()['countryCode'] ?? null) === 'CO';
        });
    }

    public function test_create_registers_callbacks_on_zapi_routes_by_default(): void
    {
        $this->fakeFunapi();
        config(['funapi.webhook_base_url' => 'https://wapi.example.com', 'funapi.webhook_status_base_url' => 'https://status.example.com']);

        (new FunapiClient())->create(7, 42);

        Http::assertSent(fn ($request) => $request->url() === 'https://funapi.example.com/instances/on-demand'
            && $request->data()['receivedAndDeliveryCallbackUrl'] === 'https://wapi.example.com/webhooks/zapi/received-and-delivery?userId=7&deviceId=42'
            && $request->data()['messageStatusCallbackUrl'] === 'https://status.example.com/webhooks/zapi/message-status?userId=7&deviceId=42'
            && $request->data()['blockCallbackUrl'] === 'https://wapi.example.com/webhooks/zapi/block?userId=7&deviceId=42');
    }

    public function test_create_uses_the_configured_webhook_path_prefix(): void
    {
        $this->fakeFunapi();
        config([
            'funapi.webhook_base_url' => 'https://hooks.example.com',
            'funapi.webhook_status_base_url' => 'https://hooks.example.com',
            'funapi.webhook_path_prefix' => '/webhooks/funapi',
        ]);

        (new FunapiClient())->create(7, 42);

        Http::assertSent(fn ($request) => $request->url() === 'https://funapi.example.com/instances/on-demand'
            && $request->data()['receivedAndDeliveryCallbackUrl'] === 'https://hooks.example.com/webhooks/funapi/received-and-delivery?userId=7&deviceId=42'
            && $request->data()['connectedCallbackUrl'] === 'https://hooks.example.com/webhooks/funapi/connected?userId=7&deviceId=42'
            && $request->data()['messageStatusCallbackUrl'] === 'https://hooks.example.com/webhooks/funapi/message-status?userId=7&deviceId=42');
    }

    public function test_create_omits_country_code_when_null(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->create(7, 42);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://funapi.example.com/instances/on-demand'
                && $request->method() === 'POST'
                && ! array_key_exists('countryCode', $request->data());
        });
    }
}
