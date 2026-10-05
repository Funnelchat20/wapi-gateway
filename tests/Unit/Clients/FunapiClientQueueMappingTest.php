<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Carbon\Carbon;
use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;

class FunapiClientQueueMappingTest extends TestCase
{
    private FunapiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'funapi.base_url' => 'https://api.whatsgo.wa-api.io',
            'funapi.client_token' => 'client-token',
        ]);

        // logRequest() dispatches StoreDeviceLogJob; keep it off the wire.
        Queue::fake();

        $this->client = new FunapiClient();
    }

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    /**
     * Regression test for issue #742.
     *
     * Funapi's `/queue` returns a bare array whose message objects use
     * camelCase keys (`messageId`, `zaapId`, `phone`, `message`, `created`),
     * unlike z-api's PascalCase (`MessageId`, `ZaapId`, ...). The payload below
     * is a real funapi queue element captured from production.
     *
     * Before the fix, `normalizeQueuedMessage` read only the PascalCase keys, so
     * every queued funapi message came back with `messageId => null`. Downstream,
     * `ReconcileDeliveriesCommand` collects ids with `isset($message['messageId'])`
     * (false for null), so the funapi queue looked empty and delivered/queued
     * sends were marked ERROR.
     */
    public function test_show_queue_maps_funapi_camelcase_message(): void
    {
        Http::fake([
            'https://api.whatsgo.wa-api.io/instances/UID/token/TOKEN/queue*' => Http::response([
                [
                    '_id' => '178999992175943267722eff1a7',
                    'phone' => '5493764375872-1621087474@g.us',
                    'status' => 'available',
                    'zaapId' => '178999992175943267722eff1a7',
                    'created' => 1789999921761,
                    'message' => 'test',
                    'messageId' => '3EB0CF1DBEBD564A7078FC',
                    'instanceId' => '72fad8ff-7e0b-40d7-a2e6-f8201aceb470',
                ],
            ], 200),
        ]);

        $result = $this->client->showQueue('UID', 'TOKEN');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertCount(1, $result['messages']);

        $message = $result['messages'][0];

        // The id must survive normalization — this is what the reconcile matches on.
        $this->assertSame('3EB0CF1DBEBD564A7078FC', $message['messageId']);
        $this->assertSame('178999992175943267722eff1a7', $message['ZaapId']);
        $this->assertSame('5493764375872-1621087474@g.us', $message['phone']);
        $this->assertSame('test', $message['message']);
        // Funapi sends the timestamp under the lowercase `created` key.
        $this->assertSame(
            Carbon::createFromTimestampMs(1789999921761)->toIso8601String(),
            $message['created']
        );
    }
}
