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
        $number = '5511999999999';
        $message = 'Hello world';
        $endpoint = 'https://funapi.test/instances/UID/token/TOKEN/send-text';

        Http::fake([
            $endpoint => Http::response([
                'messageId' => 'msg-456',
            ], 200),
        ]);

        $result = $this->client->sendText('UID', 'TOKEN', $number, $message);

        $this->assertInstanceOf(MessageResultData::class, $result);
        $this->assertTrue($result->sent);
        $this->assertSame('msg-456', $result->id);

        Http::assertSent(function ($request) use ($number, $message, $endpoint) {
            $clientToken = $request->header('Client-Token')[0] ?? null;

            return $request->url() === $endpoint
                && $request['phone'] === $number
                && $request['message'] === $message
                && $clientToken === 'client-token';
        });

        Http::assertSentCount(1);
    }

    public function test_send_text_throws_wapi_exception_on_failure(): void
    {
        $endpoint = 'https://funapi.test/instances/UID/token/TOKEN/send-text';

        Http::fake([
            $endpoint => Http::response([
                'error' => 'Whatsapp not connected',
            ], 422),
        ]);

        $this->expectException(WapiException::class);
        $this->expectExceptionMessage('not_connected');

        $this->client->sendText('UID', 'TOKEN', '5511999999999', 'Hello world');
    }
}
