<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * FunapiClient overrides sendPtv instead of inheriting Z-API's, which sends the
 * video in the `ptv` field. FunApi expects `video`, so the override needs its
 * own coverage to guarantee the payload carries `video` (and never `ptv`).
 */
class FunapiClientPtvTest extends TestCase
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

    public function test_send_ptv_sends_the_video_field_not_ptv(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->sendPtv('UID', 'TOKEN', '5491100000000', 'https://cdn.example.com/clip.mp4');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), 'send-ptv')
                && ! array_key_exists('ptv', $data)
                && ($data['video'] ?? null) === 'https://cdn.example.com/clip.mp4'
                && ($data['phone'] ?? null) === '5491100000000';
        });
    }

    public function test_send_ptv_maps_the_supported_options(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->sendPtv('UID', 'TOKEN', '5491100000000', 'https://cdn.example.com/clip.mp4', [
            'caption' => 'Mirá esto',
            'viewOnce' => true,
            'delayMessage' => 13,
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return ($data['caption'] ?? null) === 'Mirá esto'
                && ($data['viewOnce'] ?? null) === true
                && ($data['delayMessage'] ?? null) === 13;
        });
    }

    public function test_send_ptv_quotes_an_existing_message_via_message_id(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->sendPtv('UID', 'TOKEN', '5491100000000', 'https://cdn.example.com/clip.mp4', [
            'messageId' => 'MSG-ABC',
        ]);

        Http::assertSent(fn($request) => ($request->data()['messageId'] ?? null) === 'MSG-ABC');
    }
}
