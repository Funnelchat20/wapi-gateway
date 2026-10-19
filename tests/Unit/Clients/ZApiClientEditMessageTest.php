<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\ZApiLiteClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * Editing a message already sent: Z-API has no edit endpoint of its own, it is
 * `send-text` carrying the optional `editMessageId`.
 */
class ZApiClientEditMessageTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function fakeZApi(array $response = ['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-NEW'], int $status = 200): void
    {
        config([
            'zapi.base_url' => 'https://zapi.example.com',
            'zapi.client_token' => 'zapi-client-token',
        ]);

        Http::fake(['https://zapi.example.com/*' => Http::response($response, $status)]);
    }

    public function test_edit_message_posts_send_text_with_the_edit_message_id(): void
    {
        $this->fakeZApi();

        (new ZApiClient())->editMessage('UID', 'TOKEN', '120363000000000000-group', 'MSG-1', 'Texto corregido');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://zapi.example.com/instances/UID/token/TOKEN/send-text'
                && $request->hasHeader('Client-Token', 'zapi-client-token')
                && $request->data() === [
                    'phone' => '120363000000000000-group',
                    'message' => 'Texto corregido',
                    'editMessageId' => 'MSG-1',
                ];
        });
        Http::assertSentCount(1);
    }

    /**
     * The guard for the key collision: `messageId` is the quote option, so an
     * edit built with that key would reply to the message instead of replacing
     * it. `editMessageId` must never turn into a quote.
     */
    public function test_edit_message_does_not_quote_the_original(): void
    {
        $this->fakeZApi();

        (new ZApiClient())->editMessage('UID', 'TOKEN', '5491100000000', 'MSG-1', 'Texto corregido');

        Http::assertSent(fn ($request) => ! array_key_exists('messageId', $request->data()));
    }

    /** An edit is not a send: no retry, so a landed edit is never re-applied blind. */
    public function test_edit_message_does_not_retry(): void
    {
        $this->fakeZApi();

        (new ZApiClient())->editMessage('UID', 'TOKEN', '5491100000000', 'MSG-1', 'Texto corregido');

        Http::assertSentCount(1);
    }

    /**
     * An edit comes back through the same `MessageResource` as a send, so the
     * caller reads `sent` and `id` exactly as it does after `sendText()` — no
     * second shape to handle.
     */
    public function test_edit_message_returns_the_send_text_resource_on_success(): void
    {
        $this->fakeZApi();

        $result = (new ZApiClient())->editMessage('UID', 'TOKEN', '5491100000000', 'MSG-1', 'Texto corregido');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue($result['sent']);
        $this->assertSame('MSG-NEW', $result['id']);
        $this->assertSame('ZAAP-1', $result['queueId']);
    }

    /**
     * The 15-minute window is WhatsApp's and this client keeps no clock: when
     * it has passed, the rejection arrives from the provider and comes back as
     * the `error` shape the rest of the client uses.
     */
    public function test_a_rejected_edit_comes_back_as_an_error(): void
    {
        $this->fakeZApi(['error' => 'Message not found or too old to edit'], 400);

        $result = (new ZApiClient())->editMessage('UID', 'TOKEN', '5491100000000', 'MSG-1', 'Texto corregido');

        $this->assertSame('Message not found or too old to edit', $result['error'] ?? null);
    }

    public function test_zapi_lite_inherits_edit_message_and_uses_its_own_config_prefix(): void
    {
        config([
            'zapi-lite.base_url' => 'https://lite.z-api.io',
            'zapi-lite.client_token' => 'lite-client-token',
        ]);

        Http::fake(['https://lite.z-api.io/*' => Http::response(['messageId' => 'MSG-NEW'], 200)]);

        (new ZApiLiteClient())->editMessage('UID', 'TOKEN', '5491100000000', 'MSG-1', 'Texto corregido');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://lite.z-api.io/instances/UID/token/TOKEN/send-text'
                && $request->hasHeader('Client-Token', 'lite-client-token')
                && ($request->data()['editMessageId'] ?? null) === 'MSG-1';
        });
    }

    /**
     * `communities` turns the "edit a sent message" feature on by probing
     * `method_exists($client, 'editMessage')` — there is no flag. The name and
     * the signature are the contract; renaming either leaves the feature dark
     * with no error anywhere.
     */
    public function test_the_method_communities_probes_for_keeps_its_name_and_signature(): void
    {
        foreach ([ZApiClient::class, ZApiLiteClient::class] as $client) {
            $this->assertTrue(method_exists($client, 'editMessage'), "$client lost editMessage");

            $parameters = (new \ReflectionMethod($client, 'editMessage'))->getParameters();

            $this->assertSame(
                ['uid', 'token', 'phone', 'messageId', 'text'],
                array_map(fn (\ReflectionParameter $p) => $p->getName(), $parameters),
            );
        }
    }

    /** Plain sends stay untouched: no `editMessageId` unless it was asked for. */
    public function test_send_text_without_the_option_sends_no_edit_message_id(): void
    {
        $this->fakeZApi();

        (new ZApiClient())->sendText('UID', 'TOKEN', '5491100000000', 'Hola');

        Http::assertSent(fn ($request) => ! array_key_exists('editMessageId', $request->data()));
    }
}
