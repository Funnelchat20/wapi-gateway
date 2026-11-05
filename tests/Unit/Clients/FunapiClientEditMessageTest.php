<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * whatsgo edits a sent text through `send-text` carrying `editMessageId`
 * (fun-api#211, whatsgo 3.113.0) — the z-api contract. The assertion that
 * matters is that the edit reaches the wire WITH `editMessageId`: without it
 * the same call is a plain send, i.e. a second message in the chat.
 */
class FunapiClientEditMessageTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function fakeFunapi(array $body = ['zaapId' => 'MSG-ORIGINAL', 'messageId' => 'MSG-ORIGINAL', 'id' => 'MSG-ORIGINAL'], int $status = 200): void
    {
        config([
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);

        Http::fake([
            'https://funapi.example.com/*' => Http::response($body, $status),
        ]);
    }

    public function test_edit_message_posts_send_text_with_edit_message_id(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->editMessage('UID', 'TOKEN', '120363000000000000-group', 'MSG-ORIGINAL', 'Texto corregido');

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            return str_ends_with($request->url(), '/instances/UID/token/TOKEN/send-text')
                && $request->method() === 'POST'
                && $request['phone'] === '120363000000000000-group'
                && $request['message'] === 'Texto corregido'
                && $request['editMessageId'] === 'MSG-ORIGINAL'
                && ! isset($request['messageId']);
        });
    }

    public function test_edit_message_returns_the_message_resource_on_success(): void
    {
        $this->fakeFunapi();

        $result = (new FunapiClient())->editMessage('UID', 'TOKEN', '120363000000000000-group', 'MSG-ORIGINAL', 'Texto corregido');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('MSG-ORIGINAL', $result['id'] ?? null);
    }

    public function test_edit_message_surfaces_the_provider_refusal_as_an_error(): void
    {
        $this->fakeFunapi(['error' => 'edit target is not a confirmed API message'], 409);

        $result = (new FunapiClient())->editMessage('UID', 'TOKEN', '120363000000000000-group', 'MSG-ORIGINAL', 'Texto corregido');

        $this->assertArrayHasKey('error', $result);
    }

    public function test_send_text_without_edit_option_sends_no_edit_message_id(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->sendText('UID', 'TOKEN', '120363000000000000-group', 'Hola');

        Http::assertSent(fn (Request $request) => ! array_key_exists('editMessageId', $request->data()));
    }

    public function test_blank_edit_message_id_is_not_forwarded(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->sendText('UID', 'TOKEN', '120363000000000000-group', 'Hola', ['editMessageId' => '  ']);

        Http::assertSent(fn (Request $request) => ! array_key_exists('editMessageId', $request->data()));
    }

    public function test_edit_message_is_declared_on_funapi_client(): void
    {
        $this->assertSame(
            FunapiClient::class,
            (new \ReflectionMethod(FunapiClient::class, 'editMessage'))->getDeclaringClass()->getName()
        );
    }
}
