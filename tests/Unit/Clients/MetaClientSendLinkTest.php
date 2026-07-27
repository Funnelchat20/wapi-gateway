<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class MetaClientSendLinkTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function fakeGraph(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.OUT']],
            ], 200),
        ]);
    }

    /** The whole point of the fix: without preview_url the card never renders. */
    public function test_send_link_sets_preview_url_inside_the_text_object(): void
    {
        $this->fakeGraph();

        (new MetaClient())->sendLink('WABA', 'TOKEN', '5491100000000', 'Mirá esto', 'https://tienda.example.com/promo');

        Http::assertSent(function ($request) {
            $text = $request->data()['text'] ?? [];

            return $request->method() === 'POST'
                && ($request->data()['type'] ?? null) === 'text'
                // preview_url belongs inside `text`, not at the top level.
                && ($text['preview_url'] ?? null) === true
                && ! array_key_exists('preview_url', $request->data())
                // Body composition is contractual: `conversations` persists the
                // same "message + space + url" string in the agent's history.
                && ($text['body'] ?? null) === 'Mirá esto https://tienda.example.com/promo';
        });
    }

    public function test_send_link_fires_the_typing_indicator_when_a_last_inbound_id_is_given(): void
    {
        $this->fakeGraph();

        (new MetaClient())->sendLink('WABA', 'TOKEN', '5491100000000', 'Mirá esto', 'https://tienda.example.com/promo', [
            'typing' => ['lastInboundId' => 'wamid.IN', 'delaySeconds' => 3],
        ]);

        // The indicator rides on the mark-as-read call for the inbound wamid.
        Http::assertSent(fn($request) => ($request->data()['status'] ?? null) === 'read'
            && ($request->data()['message_id'] ?? null) === 'wamid.IN'
            && isset($request->data()['typing_indicator']));
        Http::assertSentCount(2);
    }

    public function test_send_link_skips_the_typing_indicator_without_a_last_inbound_id(): void
    {
        $this->fakeGraph();

        (new MetaClient())->sendLink('WABA', 'TOKEN', '5491100000000', 'Mirá esto', 'https://tienda.example.com/promo', [
            'typing' => ['delaySeconds' => 3],
        ]);

        // Only the send: no wamid means there is nothing to attach the
        // indicator to, and that is not an error.
        Http::assertSentCount(1);
    }

    public function test_send_button_link_fires_the_typing_indicator_and_keeps_the_cta_payload(): void
    {
        $this->fakeGraph();

        (new MetaClient())->sendButtonLink('WABA', 'TOKEN', '5491100000000', 'Mirá esto', 'https://tienda.example.com/promo', 'Ver promo', [
            'typing' => ['lastInboundId' => 'wamid.IN'],
        ]);

        Http::assertSent(fn($request) => ($request->data()['message_id'] ?? null) === 'wamid.IN');
        Http::assertSent(fn($request) => ($request->data()['interactive']['type'] ?? null) === 'cta_url'
            && ($request->data()['interactive']['action']['parameters']['url'] ?? null) === 'https://tienda.example.com/promo');
    }

    /** A failing indicator must never take the send down with it. */
    public function test_a_failing_typing_indicator_still_sends_the_link(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['message' => 'outside 24h window']], 400)
            ->push(['messages' => [['id' => 'wamid.OUT']]], 200);

        $result = (new MetaClient())->sendLink('WABA', 'TOKEN', '5491100000000', 'Mirá esto', 'https://tienda.example.com/promo', [
            'typing' => ['lastInboundId' => 'wamid.STALE'],
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertArrayHasKey('typing_result', $result);
    }
}
