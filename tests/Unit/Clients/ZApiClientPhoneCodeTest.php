<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Clients\UazapiClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class ZApiClientPhoneCodeTest extends TestCase
{
    private ZApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'zapi.base_url' => 'https://api.z-api.io',
            'zapi.client_token' => 'client-token',
        ]);

        $this->client = new ZApiClient();
    }

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    public function test_phone_code_returns_the_pairing_code(): void
    {
        Http::fake([
            'https://api.z-api.io/instances/UID/token/TOKEN/phone-code/5511999999999' => Http::response([
                'value' => 'A1B2C3D4E5',
            ], 200),
        ]);

        $result = $this->client->phoneCode('UID', 'TOKEN', '5511999999999');

        $this->assertSame(['code' => 'A1B2C3D4E5', 'challenge' => false], $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.z-api.io/instances/UID/token/TOKEN/phone-code/5511999999999'
                && $request->hasHeader('Client-Token', 'client-token');
        });
    }

    public function test_phone_code_surfaces_a_passkey_challenge_as_unavailable(): void
    {
        Http::fake([
            'https://api.z-api.io/instances/UID/token/TOKEN/phone-code/5511999999999' => Http::response([
                'challenge' => ['challenge' => 'abc', 'rpId' => 'whatsapp.com', 'timeout' => 600000],
            ], 200),
        ]);

        $result = $this->client->phoneCode('UID', 'TOKEN', '5511999999999');

        $this->assertSame(['code' => null, 'challenge' => true], $result);
    }

    public function test_phone_code_maps_rate_limit_response(): void
    {
        Http::fake([
            'https://api.z-api.io/instances/UID/token/TOKEN/phone-code/5511999999999' => Http::response(
                ['error' => 'Too many requests'],
                429
            ),
        ]);

        $result = $this->client->phoneCode('UID', 'TOKEN', '5511999999999');

        $this->assertSame('rate_limited', $result['error']);
        $this->assertSame(429, $result['status']);
    }

    public function test_phone_code_maps_upstream_error(): void
    {
        Http::fake([
            'https://api.z-api.io/instances/UID/token/TOKEN/phone-code/5511999999999' => Http::response(
                ['error' => 'Instance not found'],
                400
            ),
        ]);

        $result = $this->client->phoneCode('UID', 'TOKEN', '5511999999999');

        $this->assertSame('upstream_error', $result['error']);
    }

    public function test_funapi_shares_the_route_but_answers_the_code_under_a_different_field(): void
    {
        // Same route/method as Z-API (api/internal/http/handlers/instances.go:127), but the
        // success body carries the code under `code`, not `value` — confirmed against FunAPI's
        // own handler (instances.go:303). FunApi never sends `challenge` either. Getting this
        // field wrong meant a silently empty code for FunApi, the forced provider for every new
        // device — i.e. the common case, not an edge case.
        config([
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-token',
        ]);

        Http::fake([
            'https://funapi.example.com/instances/UID/token/TOKEN/phone-code/5511999999999' => Http::response([
                'code' => 'ABCD-EFGH',
            ], 200),
        ]);

        $result = (new FunapiClient())->phoneCode('UID', 'TOKEN', '5511999999999');

        $this->assertSame(['code' => 'ABCD-EFGH', 'challenge' => false], $result);
    }

    public function test_uazapi_reports_phone_code_as_unsupported(): void
    {
        $result = (new UazapiClient())->phoneCode('UID', 'TOKEN', '5511999999999');

        $this->assertSame('unsupported', $result['error']);
    }

    public function test_meta_reports_phone_code_as_unsupported(): void
    {
        $result = (new MetaClient())->phoneCode('UID', 'TOKEN', '5511999999999');

        $this->assertSame('unsupported', $result['error']);
    }
}
