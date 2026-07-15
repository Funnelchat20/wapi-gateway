<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Jobs\StoreDeviceLogJob;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;

class ZApiClientBusinessProfileTest extends TestCase
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

    public function test_business_profile_maps_full_response(): void
    {
        Http::fake([
            'https://api.z-api.io/instances/UID/token/TOKEN/business/profile' => Http::response([
                'description' => 'We sell shoes',
                'websites' => ['https://example.com'],
                'email' => 'sales@example.com',
                'address' => '123 Main St',
                'categories' => ['Retail'],
                'businessHours' => ['mon' => '09:00-18:00'],
                'hasCoverPhoto' => true,
            ], 200),
        ]);

        $result = $this->client->businessProfile('UID', 'TOKEN');

        $this->assertSame('We sell shoes', $result['description']);
        $this->assertSame(['https://example.com'], $result['website']);
        $this->assertSame('sales@example.com', $result['email']);
        $this->assertSame('123 Main St', $result['address']);
        $this->assertSame(['Retail'], $result['categories']);
        $this->assertSame(['mon' => '09:00-18:00'], $result['businessHours']);
        $this->assertTrue($result['hasCoverPhoto']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.z-api.io/instances/UID/token/TOKEN/business/profile'
                && $request->hasHeader('Client-Token', 'client-token');
        });
    }

    public function test_business_profile_returns_empty_defaults_for_non_business_account(): void
    {
        // A personal (non-business) account resolves this endpoint with an
        // empty/partial payload rather than an error — must still degrade
        // to the full empty-defaults shape.
        Http::fake([
            'https://api.z-api.io/instances/UID/token/TOKEN/business/profile' => Http::response([], 200),
        ]);

        $result = $this->client->businessProfile('UID', 'TOKEN');

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

    public function test_business_profile_returns_empty_defaults_on_error_response(): void
    {
        Http::fake([
            'https://api.z-api.io/instances/UID/token/TOKEN/business/profile' => Http::response(['error' => 'Instance not found'], 400),
        ]);

        $result = $this->client->businessProfile('UID', 'TOKEN');

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

    public function test_business_profile_does_not_throw_on_upstream_server_error(): void
    {
        Http::fake([
            'https://api.z-api.io/*' => Http::response(null, 500),
        ]);

        $result = $this->client->businessProfile('UID', 'TOKEN');

        $this->assertSame([], $result['website']);
        $this->assertFalse($result['hasCoverPhoto']);
    }

    public function test_business_profile_returns_empty_defaults_on_connection_failure(): void
    {
        // Simulates a DNS/timeout/connection-refused failure — no HTTP
        // response is ever produced, so this exercises the try/catch path
        // (distinct from `$res->failed()`, which needs an actual response).
        Http::fake(function () {
            throw new ConnectionException('Could not resolve host: api.z-api.io');
        });

        $result = $this->client->businessProfile('UID', 'TOKEN');

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

    public function test_business_profile_does_not_persist_pii_to_device_log_when_logging_enabled(): void
    {
        config(['wapi-gateway.logging_enabled' => true]);
        Queue::fake();

        Http::fake([
            'https://api.z-api.io/instances/UID/token/TOKEN/business/profile' => Http::response([
                'description' => 'We sell shoes',
                'email' => 'sales@example.com',
                'address' => '123 Main St',
                'hasCoverPhoto' => true,
            ], 200),
        ]);

        $this->client->businessProfile('UID', 'TOKEN');

        Queue::assertPushed(StoreDeviceLogJob::class, function (StoreDeviceLogJob $job) {
            $reflection = new \ReflectionObject($job);

            $responseBody = $reflection->getProperty('responseBody');
            $responseBody->setAccessible(true);
            $this->assertNull(
                $responseBody->getValue($job),
                'businessProfile() response body (may contain email/address) must never be queued for persistence.'
            );

            $statusCode = $reflection->getProperty('statusCode');
            $statusCode->setAccessible(true);
            $this->assertSame(200, $statusCode->getValue($job));

            return true;
        });
    }

    public function test_me_maps_about_field_when_present(): void
    {
        Http::fake([
            'https://api.z-api.io/instances/UID/token/TOKEN/device' => Http::response([
                'phone' => '5511999999999',
                'name' => 'Jane',
                'imgUrl' => 'https://example.com/avatar.jpg',
                'isBusiness' => false,
                'about' => 'Hey there! I am using WhatsApp.',
            ], 200),
        ]);

        $result = $this->client->me('UID', 'TOKEN');

        $this->assertSame('Hey there! I am using WhatsApp.', $result['about']);
    }

    public function test_me_defaults_about_to_empty_string_when_absent_from_upstream(): void
    {
        Http::fake([
            'https://api.z-api.io/instances/UID/token/TOKEN/device' => Http::response([
                'phone' => '5511999999999',
                'name' => 'Jane',
            ], 200),
        ]);

        $result = $this->client->me('UID', 'TOKEN');

        $this->assertArrayHasKey('about', $result);
        $this->assertSame('', $result['about']);
    }
}
