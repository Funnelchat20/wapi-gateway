<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\ZApiLiteClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class ZApiLiteClientBusinessProfileTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    public function test_business_profile_is_inherited_and_uses_zapi_lite_config_prefix(): void
    {
        config([
            'zapi-lite.base_url' => 'https://lite.z-api.io',
            'zapi-lite.client_token' => 'lite-client-token',
        ]);

        Http::fake([
            'https://lite.z-api.io/instances/UID/token/TOKEN/business/profile' => Http::response([
                'description' => 'Lite business',
                'websites' => ['https://lite-shop.example.com'],
            ], 200),
        ]);

        $client = new ZApiLiteClient();
        $result = $client->businessProfile('UID', 'TOKEN');

        $this->assertSame('Lite business', $result['description']);
        $this->assertSame(['https://lite-shop.example.com'], $result['website']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://lite.z-api.io/instances/UID/token/TOKEN/business/profile'
                && $request->hasHeader('Client-Token', 'lite-client-token');
        });
    }
}
