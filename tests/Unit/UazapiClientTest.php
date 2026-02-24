<?php

namespace Funnelchat\WapiGateway\Tests\Unit;

use Funnelchat\WapiGateway\Clients\UazapiClient;
use Funnelchat\WapiGateway\Data\MessageResultData;
use Funnelchat\WapiGateway\Exceptions\WapiException;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class UazapiClientTest extends TestCase
{
    private UazapiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'uazapi.base_url' => 'https://uazapi.test',
            'uazapi.endpoints.send_message' => '/send-text',
            'uazapi.timeout' => 10,
        ]);

        $this->client = new UazapiClient();
    }

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    public function test_send_text_returns_message_result_dto(): void
    {
        $number = '5511999999999';
        $message = 'Hello world';

        Http::fake([
            'https://uazapi.test/send-text' => Http::response([
                'messageId' => 'msg-123',
            ], 200),
        ]);

        $result = $this->client->sendText('UID', 'TOKEN', $number, $message);

        $this->assertInstanceOf(MessageResultData::class, $result);
        $this->assertTrue($result->sent);
        $this->assertSame('msg-123', $result->id);

        Http::assertSent(function ($request) use ($number, $message) {
            $token = $request->header('token')[0] ?? null;

            return $request->url() === 'https://uazapi.test/send-text'
                && $request['number'] === $number
                && $request['text'] === $message
                && $token === 'TOKEN';
        });

        Http::assertSentCount(1);
    }

    public function test_send_text_throws_wapi_exception_on_failure(): void
    {
        Http::fake([
            'https://uazapi.test/send-text' => Http::response([
                'error' => 'Whatsapp not connected',
            ], 422),
        ]);

        $this->expectException(WapiException::class);
        $this->expectExceptionMessage('Whatsapp not connected');

        $this->client->sendText('UID', 'TOKEN', '5511999999999', 'Hello world');
    }
}
