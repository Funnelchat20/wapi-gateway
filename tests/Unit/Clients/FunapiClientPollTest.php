<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * FunapiClient overrides sendPoll instead of inheriting Z-API's, so the poll
 * payload shape needs its own coverage to guarantee both providers receive
 * the same `poll` structure ([{name: ...}]) for the same input.
 */
class FunapiClientPollTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function fakeProviders(): void
    {
        config([
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
            'zapi.base_url' => 'https://zapi.example.com',
            'zapi.client_token' => 'zapi-client-token',
        ]);

        Http::fake([
            '*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1'], 200),
        ]);
    }

    public function test_send_poll_wraps_options_as_name_objects_like_zapi(): void
    {
        $this->fakeProviders();

        (new FunapiClient())->sendPoll('UID', 'TOKEN', '5491100000000', '¿Cuál preferís?', ['Rojo', 'Azul']);

        Http::assertSent(fn($request) => str_contains($request->url(), 'send-poll')
            && $request->data()['poll'] === [['name' => 'Rojo'], ['name' => 'Azul']]);
    }

    public function test_send_poll_matches_zapi_poll_payload_for_the_same_input(): void
    {
        $this->fakeProviders();

        $options = ['Rojo', 'Azul', 'Verde'];

        (new ZApiClient())->sendPoll('UID', 'TOKEN', '5491100000000', '¿Cuál preferís?', $options);
        (new FunapiClient())->sendPoll('UID', 'TOKEN', '5491100000000', '¿Cuál preferís?', $options);

        $polls = [];
        foreach (Http::recorded() as [$request, $response]) {
            if (! str_contains($request->url(), 'send-poll')) {
                continue;
            }
            $provider = str_contains($request->url(), 'zapi.example.com') ? 'zapi' : 'funapi';
            $polls[$provider] = $request->data()['poll'];
        }

        $this->assertArrayHasKey('zapi', $polls);
        $this->assertArrayHasKey('funapi', $polls);
        $this->assertSame($polls['zapi'], $polls['funapi']);
    }
}
