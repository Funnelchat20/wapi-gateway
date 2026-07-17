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
