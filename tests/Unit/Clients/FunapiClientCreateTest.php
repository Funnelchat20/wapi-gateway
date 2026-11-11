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

    public function test_create_body_is_unchanged_without_a_history_sync_callback_url(): void
    {
        $this->fakeFunapi();
        config(['funapi.webhook_base_url' => 'https://wapi.example.com']);

        (new FunapiClient())->create(7, 42, 'co');

        // The exact body sent before historySyncCallbackUrl existed: no new key
        // unless the caller asks for it.
        Http::assertSent(function ($request) {
            return $request->url() === 'https://funapi.example.com/instances/on-demand'
                && $request->data() === [
                    'name' => 'U-7 D-42',
                    'sessionName' => 'Funnelchat',
                    'countryCode' => 'CO',
                    'receivedCallbackUrl' => 'https://wapi.example.com/webhooks/zapi/received?userId=7&deviceId=42',
                    'receivedAndDeliveryCallbackUrl' => 'https://wapi.example.com/webhooks/zapi/received-and-delivery?userId=7&deviceId=42',
                    'disconnectedCallbackUrl' => 'https://wapi.example.com/webhooks/zapi/disconnected?userId=7&deviceId=42',
                    'connectedCallbackUrl' => 'https://wapi.example.com/webhooks/zapi/connected?userId=7&deviceId=42',
                    'messageStatusCallbackUrl' => 'https://wapi.example.com/webhooks/zapi/message-status?userId=7&deviceId=42',
                    'blockCallbackUrl' => 'https://wapi.example.com/webhooks/zapi/block?userId=7&deviceId=42',
                ];
        });
    }

    public function test_create_omits_an_empty_history_sync_callback_url(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->create(7, 42, null, '');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://funapi.example.com/instances/on-demand'
                && ! array_key_exists('historySyncCallbackUrl', $request->data());
        });
    }

    public function test_create_sends_the_history_sync_callback_url_when_given(): void
    {
        $this->fakeFunapi();
        config(['funapi.webhook_base_url' => 'https://hooks.example.com']);

        $historyUrl = 'https://wapi.example.com/webhooks/funapi/history?deviceId=42';

        $result = (new FunapiClient())->create(7, 42, 'co', historySyncCallbackUrl: $historyUrl);

        $this->assertSame(['uid' => 'UID-1', 'token' => 'TOKEN-1'], $result);

        // Sent verbatim, next to the other callbacks: it does not follow
        // funapi.webhook_base_url (the caller picks the host).
        Http::assertSent(function ($request) use ($historyUrl) {
            return $request->url() === 'https://funapi.example.com/instances/on-demand'
                && $request->method() === 'POST'
                && ($request->data()['historySyncCallbackUrl'] ?? null) === $historyUrl
                && ($request->data()['countryCode'] ?? null) === 'CO'
                && str_starts_with($request->data()['receivedCallbackUrl'] ?? '', 'https://hooks.example.com/webhooks/zapi/');
        });
    }

    public function test_create_sends_the_history_sync_callback_url_without_webhooks_configured(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->create(7, 42, null, 'https://wapi.example.com/webhooks/funapi/history?deviceId=42');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://funapi.example.com/instances/on-demand'
                && ($request->data()['historySyncCallbackUrl'] ?? null) === 'https://wapi.example.com/webhooks/funapi/history?deviceId=42'
                && ! array_key_exists('receivedCallbackUrl', $request->data())
                && ! array_key_exists('countryCode', $request->data());
        });
    }
}
