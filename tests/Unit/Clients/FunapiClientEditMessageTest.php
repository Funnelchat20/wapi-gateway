<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * `FunapiClient extends ZApiClient`, so z-api's `editMessage()` — which is
 * `sendText()` carrying `editMessageId` — came for free. funapi's `sendText()`
 * has no such key, so the edit left as a plain send: a second message in the
 * chat, reported as a success. These tests pin the refusal, and the absence of
 * any outbound request is the assertion that matters.
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

        Http::fake([
            'https://funapi.example.com/*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1'], 200),
        ]);
    }

    public function test_edit_message_never_reaches_the_network(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->editMessage('UID', 'TOKEN', '120363000000000000-group', 'MSG-ORIGINAL', 'Texto corregido');

        Http::assertNothingSent();
    }

    public function test_edit_message_answers_an_error_instead_of_a_message_resource(): void
    {
        $this->fakeFunapi();

        $result = (new FunapiClient())->editMessage('UID', 'TOKEN', '120363000000000000-group', 'MSG-ORIGINAL', 'Texto corregido');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('messageId', $result);
    }

    public function test_the_refusal_overrides_z_apis_implementation(): void
    {
        $this->assertSame(
            FunapiClient::class,
            (new \ReflectionMethod(FunapiClient::class, 'editMessage'))->getDeclaringClass()->getName(),
            'editMessage() must be declared on FunapiClient, not inherited from ZApiClient.'
        );

        $this->assertSame(
            ZApiClient::class,
            (new \ReflectionMethod(ZApiClient::class, 'editMessage'))->getDeclaringClass()->getName()
        );
    }

    public function test_method_exists_still_answers_true_for_callers_that_probe_it(): void
    {
        // communities' WapiSentMessageEditor builds its provider list with
        // method_exists(). The probe keeps passing — what changed is that the
        // call now refuses rather than duplicating the message.
        $this->assertTrue(method_exists(FunapiClient::class, 'editMessage'));
    }
}
