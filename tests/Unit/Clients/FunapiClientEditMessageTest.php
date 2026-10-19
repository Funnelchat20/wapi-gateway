<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * `FunapiClient` extends `ZApiClient`, so it inherits every method it does not
 * override — including `editMessage()`. These tests pin the override that stops
 * that inheritance from turning an edit into a duplicate message.
 */
class FunapiClientEditMessageTest extends TestCase
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

        Http::fake(['https://funapi.example.com/*' => Http::response(['messageId' => 'MSG-NEW'], 200)]);
    }

    /**
     * The regression this override exists for: inherited, `editMessage()` went
     * out as a plain `send-text` with no `editMessageId` — whatsgo would have
     * posted a second message to the group and reported success.
     */
    public function test_edit_message_sends_nothing_at_all(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->editMessage('UID', 'TOKEN', '120363000000000000-group', 'MSG-1', 'Texto corregido');

        Http::assertNothingSent();
    }

    public function test_edit_message_refuses_with_a_classified_error(): void
    {
        $this->fakeFunapi();

        $result = (new FunapiClient())->editMessage('UID', 'TOKEN', '5491100000000', 'MSG-1', 'Texto corregido');

        $this->assertSame('editMessage is not supported by Funapi provider', $result['error'] ?? null);
        $this->assertArrayNotHasKey('sent', $result);
    }

    /** A refusal is not a send: the caller must never read it as a delivery. */
    public function test_the_refusal_is_not_shaped_like_a_successful_send(): void
    {
        $this->fakeFunapi();

        $result = (new FunapiClient())->editMessage('UID', 'TOKEN', '5491100000000', 'MSG-1', 'Texto corregido');

        $this->assertArrayNotHasKey('id', $result);
        $this->assertArrayNotHasKey('queueId', $result);
    }

    /** Plain sends are untouched by the override. */
    public function test_send_text_still_works_and_carries_no_edit_message_id(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->sendText('UID', 'TOKEN', '5491100000000', 'Hola');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'send-text')
                && ! array_key_exists('editMessageId', $request->data());
        });
        Http::assertSentCount(1);
    }

    /**
     * Same inheritance hazard as editMessage(): this class overrides
     * sendFile() too, so an inherited editCaption() would post a second file.
     */
    public function test_edit_caption_sends_nothing_at_all(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->editCaption('UID', 'TOKEN', '120363000000000000-group', 'MSG-1', 'https://cdn.example.com/foto.jpg', 'Nueva');

        Http::assertNothingSent();
    }

    public function test_edit_caption_refuses_with_a_classified_error(): void
    {
        $this->fakeFunapi();

        $result = (new FunapiClient())->editCaption('UID', 'TOKEN', '5491100000000', 'MSG-1', 'https://cdn.example.com/foto.jpg', 'Nueva');

        $this->assertSame('editCaption is not supported by Funapi provider', $result['error'] ?? null);
        $this->assertArrayNotHasKey('id', $result);
    }

    /** Plain media sends are untouched by the override. */
    public function test_send_file_still_works_and_carries_no_edit_key(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->sendFile('UID', 'TOKEN', '5491100000000', 'https://cdn.example.com/foto.jpg', ['caption' => 'Hola']);

        Http::assertSent(function ($request) {
            foreach (['editMessageId', 'editImageMessageId', 'editVideoMessageId', 'editDocumentMessageId'] as $key) {
                if (array_key_exists($key, $request->data())) return false;
            }

            return true;
        });
        Http::assertSentCount(1);
    }
}
