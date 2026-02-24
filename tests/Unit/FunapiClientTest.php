<?php

namespace Funnelchat\WapiGateway\Tests\Unit;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Data\MessageResultData;
use Funnelchat\WapiGateway\Exceptions\WapiException;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class FunapiClientTest extends TestCase
{
    private FunapiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'funapi.url_template' => 'https://funapi.test/instances/UID/token/TOKEN/ACTION',
            'funapi.client_token' => 'client-token',
            'funapi.timeout' => 10,
        ]);

        $this->client = new FunapiClient();
    }

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    public function test_send_text_returns_message_result_dto(): void
    {
        Http::fake([
            'https://funapi.test/*' => Http::response([
                'messageId' => 'msg-456',
            ], 200),
        ]);

        $result = $this->client->sendText('UID', 'TOKEN', '5511999999999', 'Hello world');

        $this->assertInstanceOf(MessageResultData::class, $result);
        $this->assertTrue($result->sent);
        $this->assertSame('msg-456', $result->id);
    }

    public function test_send_text_throws_wapi_exception_on_failure(): void
    {
        Http::fake([
            'https://funapi.test/*' => Http::response([
                'error' => 'Whatsapp not connected',
            ], 422),
        ]);

        $this->expectException(WapiException::class);
        $this->expectExceptionMessage('not_connected');

        $this->client->sendText('UID', 'TOKEN', '5511999999999', 'Hello world');
    }
}
