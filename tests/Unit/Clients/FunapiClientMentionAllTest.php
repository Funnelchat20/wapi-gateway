<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * The caller option is `mentionAll` (z-api's field name), but whatsgo documents
 * the wire field as `mentionedAll` in every send-* schema it supports mentions
 * on. Sending z-api's name is silent: whatsgo drops the unknown field, answers
 * 200 and delivers the text as-is, so `@all` arrives as literal text
 * (communities issue #1730). These assertions are on the payload whatsgo would
 * receive — the only place the bug is visible.
 */
class FunapiClientMentionAllTest extends TestCase
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

    public function test_send_text_sends_mentioned_all_and_never_z_apis_mention_all(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->sendText('UID', 'TOKEN', '120363000000000000-group', 'Atención @all', [
            'mentionAll' => true,
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), 'send-text')
                && ($data['mentionedAll'] ?? null) === true
                && ! array_key_exists('mentionAll', $data);
        });
    }

    public function test_send_file_sends_mentioned_all_and_never_z_apis_mention_all(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->sendFile('UID', 'TOKEN', '120363000000000000-group', 'https://cdn.example.com/flyer.png', [
            'caption' => 'Atención @all',
            'mentionAll' => true,
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), 'send-image')
                && ($data['mentionedAll'] ?? null) === true
                && ! array_key_exists('mentionAll', $data);
        });
    }

    public function test_send_text_forwards_an_explicit_false_as_a_boolean(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->sendText('UID', 'TOKEN', '120363000000000000-group', 'Sin menciones', [
            'mentionAll' => false,
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return array_key_exists('mentionedAll', $data)
                && $data['mentionedAll'] === false;
        });
    }

    public function test_send_text_omits_the_field_when_the_caller_asks_for_no_mention(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->sendText('UID', 'TOKEN', '120363000000000000-group', 'Hola');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return ! array_key_exists('mentionedAll', $data)
                && ! array_key_exists('mentionAll', $data);
        });
    }
}
