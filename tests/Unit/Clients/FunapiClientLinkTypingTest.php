<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * FunapiClient overrides sendLink/sendButtonLink instead of inheriting Z-API's,
 * so the typing wiring needs its own coverage.
 */
class FunapiClientLinkTypingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function fakeFunapi(): void
    {
        config([
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);

        Http::fake([
            'https://funapi.example.com/*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1'], 200),
        ]);
    }

    public function test_send_link_translates_the_typing_option_into_delay_typing(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->sendLink('UID', 'TOKEN', '5491100000000', 'Mirá esto', 'https://tienda.example.com/promo', [
            'typing' => ['delaySeconds' => 4],
        ]);

        Http::assertSent(fn($request) => str_contains($request->url(), 'send-link')
            && ($request->data()['delayTyping'] ?? null) === 4);
    }

    public function test_send_button_link_translates_the_typing_option_into_delay_typing(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->sendButtonLink('UID', 'TOKEN', '5491100000000', 'Mirá esto', 'https://tienda.example.com/promo', 'Ver promo', [
            'typing' => ['delaySeconds' => 4],
        ]);

        Http::assertSent(fn($request) => str_contains($request->url(), 'send-button-actions')
            && ($request->data()['delayTyping'] ?? null) === 4);
    }

    public function test_explicit_delay_typing_wins_over_the_typing_option(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->sendButtonLink('UID', 'TOKEN', '5491100000000', 'Mirá esto', 'https://tienda.example.com/promo', 'Ver promo', [
            'delayTyping' => 9,
            'typing' => ['delaySeconds' => 4],
        ]);

        Http::assertSent(fn($request) => ($request->data()['delayTyping'] ?? null) === 9);
    }

    public function test_sends_stay_unchanged_without_the_typing_option(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->sendButtonLink('UID', 'TOKEN', '5491100000000', 'Mirá esto', 'https://tienda.example.com/promo', 'Ver promo');

        Http::assertSent(fn($request) => ! array_key_exists('delayTyping', $request->data()));
    }
}
