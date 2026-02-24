<?php

namespace Funnelchat\WapiGateway\Tests\Unit;

use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Data\MessageResultData;
use Funnelchat\WapiGateway\Exceptions\WapiException;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class MetaClientTest extends TestCase
{
    private MetaClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['wapi.meta.graph_version' => 'v20.0']);

        $this->client = new MetaClient();
    }

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    public function test_send_text_returns_message_result_dto(): void
    {
        Http::fake([
            'https://graph.facebook.com/v20.0/*' => Http::response([
                'messages' => [['id' => 'wamid.123']],
            ], 200),
        ]);

        $result = $this->client->sendText('PHONE_NUMBER_ID', 'ACCESS_TOKEN', '5511999999999', 'Hola');

        $this->assertInstanceOf(MessageResultData::class, $result);
        $this->assertSame('wamid.123', $result->id);
    }

    public function test_send_text_throws_wapi_exception_on_error(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid token', 'code' => 190],
            ], 401),
        ]);

        $this->expectException(WapiException::class);
        $this->expectExceptionMessage('Invalid token');

        $this->client->sendText('PHONE_NUMBER_ID', 'BAD_TOKEN', '5511999999999', 'Hola');
    }

    public function test_send_file_returns_message_result_dto(): void
    {
        Http::fake([
            'https://graph.facebook.com/v20.0/*' => Http::response([
                'messages' => [['id' => 'wamid.img']],
            ], 200),
        ]);

        $result = $this->client->sendFile(
            'PHONE_NUMBER_ID',
            'ACCESS_TOKEN',
            '5511999999999',
            'https://example.com/photo.jpg'
        );

        $this->assertInstanceOf(MessageResultData::class, $result);
        $this->assertTrue($result->sent);
    }

    public function test_send_file_invalid_extension_throws_exception(): void
    {
        $this->expectException(WapiException::class);
        $this->expectExceptionMessage('invalid_file_extension');

        $this->client->sendFile('PHONE_NUMBER_ID', 'ACCESS_TOKEN', '5511999999999', 'https://example.com/file.xyz');
    }

    public function test_graph_url_uses_configured_version(): void
    {
        config(['wapi.meta.graph_version' => 'v21.0']);
        $client = new MetaClient();

        Http::fake([
            'https://graph.facebook.com/v21.0/*' => Http::response([
                'messages' => [['id' => 'wamid.v21']],
            ], 200),
        ]);

        $result = $client->sendText('PHONE_NUMBER_ID', 'ACCESS_TOKEN', '5511999999999', 'Hola v21');

        $this->assertInstanceOf(MessageResultData::class, $result);
        $this->assertSame('wamid.v21', $result->id);
    }
}
