<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class FunapiClientBusinessProfileTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    public function test_business_profile_is_inherited_and_uses_funapi_config_prefix(): void
    {
        config([
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);

        Http::fake([
            'https://funapi.example.com/instances/UID/token/TOKEN/business/profile' => Http::response([
                'description' => 'FunApi business',
                'websites' => ['https://funapi-shop.example.com'],
            ], 200),
        ]);

        $client = new FunapiClient();
        $result = $client->businessProfile('UID', 'TOKEN');

        $this->assertSame('FunApi business', $result['description']);
        $this->assertSame(['https://funapi-shop.example.com'], $result['website']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://funapi.example.com/instances/UID/token/TOKEN/business/profile'
                && $request->hasHeader('Client-Token', 'funapi-client-token');
        });
    }

    public function test_business_profile_degrades_gracefully_when_upstream_endpoint_is_missing(): void
    {
        // Documents the fallback for a self-hosted whatsgo backend that does
        // not (yet) implement this endpoint: no exception, empty defaults.
        config([
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);

        Http::fake([
            'https://funapi.example.com/*' => Http::response(['error' => 'Not Found'], 404),
        ]);

        $client = new FunapiClient();
        $result = $client->businessProfile('UID', 'TOKEN');

        $this->assertSame([
            'description' => '',
            'website' => [],
            'email' => '',
            'address' => '',
            'categories' => [],
            'businessHours' => [],
            'hasCoverPhoto' => false,
        ], $result);
    }

    public function test_me_maps_about_via_the_shared_zapi_me_resource(): void
    {
        config([
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);

        Http::fake([
            'https://funapi.example.com/instances/UID/token/TOKEN/device' => Http::response([
                'phone' => '5511999999999',
                'name' => 'Real Pushname',
                'about' => 'On call',
            ], 200),
        ]);

        $client = new FunapiClient();
        $result = $client->me('UID', 'TOKEN');

        $this->assertSame('On call', $result['about']);
    }
}
