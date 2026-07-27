<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class ZApiClientLinkTypingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function fakeZApi(): void
    {
        config([
            'zapi.base_url' => 'https://zapi.example.com',
            'zapi.client_token' => 'zapi-client-token',
        ]);

        Http::fake([
            'https://zapi.example.com/*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1'], 200),
        ]);
    }

    public function test_send_link_translates_the_typing_option_into_delay_typing(): void
    {
        $this->fakeZApi();

        (new ZApiClient())->sendLink('UID', 'TOKEN', '5491100000000', 'Mirá esto', 'https://tienda.example.com/promo', [
            'typing' => ['lastInboundId' => 'wamid.IN', 'delaySeconds' => 4],
        ]);

        Http::assertSent(fn($request) => str_contains($request->url(), 'send-link')
            && ($request->data()['delayTyping'] ?? null) === 4);
    }

    /** Documented precedence: an explicit delayTyping wins over `typing`. */
    public function test_explicit_delay_typing_wins_over_the_typing_option_on_send_link(): void
    {
        $this->fakeZApi();

        (new ZApiClient())->sendLink('UID', 'TOKEN', '5491100000000', 'Mirá esto', 'https://tienda.example.com/promo', [
            'delayTyping' => 9,
            'typing' => ['delaySeconds' => 4],
        ]);

        Http::assertSent(fn($request) => ($request->data()['delayTyping'] ?? null) === 9);
    }

    public function test_send_button_link_translates_the_typing_option_into_delay_typing(): void
    {
        $this->fakeZApi();

        (new ZApiClient())->sendButtonLink('UID', 'TOKEN', '5491100000000', 'Mirá esto', 'https://tienda.example.com/promo', 'Ver promo', [
            'typing' => ['delaySeconds' => 4],
        ]);

        Http::assertSent(fn($request) => str_contains($request->url(), 'send-button-actions')
            && ($request->data()['delayTyping'] ?? null) === 4);
    }

    public function test_explicit_delay_typing_wins_over_the_typing_option_on_send_button_link(): void
    {
        $this->fakeZApi();

        (new ZApiClient())->sendButtonLink('UID', 'TOKEN', '5491100000000', 'Mirá esto', 'https://tienda.example.com/promo', 'Ver promo', [
            'delayTyping' => 9,
            'typing' => ['delaySeconds' => 4],
        ]);

        Http::assertSent(fn($request) => ($request->data()['delayTyping'] ?? null) === 9);
    }

    /** No `typing` option means no delayTyping at all — not delayTyping=0. */
    public function test_sends_stay_unchanged_without_the_typing_option(): void
    {
        $this->fakeZApi();

        $client = new ZApiClient();
        $client->sendLink('UID', 'TOKEN', '5491100000000', 'Mirá esto', 'https://tienda.example.com/promo');
        $client->sendButtonLink('UID', 'TOKEN', '5491100000000', 'Mirá esto', 'https://tienda.example.com/promo', 'Ver promo');

        Http::assertSent(fn($request) => ! array_key_exists('delayTyping', $request->data()));
        Http::assertSentCount(2);
    }
}
