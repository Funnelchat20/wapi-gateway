<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\ZApiLiteClient;
use Funnelchat\WapiGateway\Contracts\ReadReceiptsContract;
use Funnelchat\WapiGateway\Enums\ProviderEnum;
use Funnelchat\WapiGateway\Facades\WapiGateway;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ReadReceiptsContract on every provider GatewayManager resolves. Each wire
 * shape is pinned against the provider's documentation, quoted on the method
 * itself. Each refusal is pinned to send nothing: an unsupported read receipt
 * must never turn into a request the provider was not asked to accept.
 */
class ReadReceiptsTest extends TestCase
{
    private const WAMID = 'wamid.HBgNNTQ5MTEyMzQ1Njc4ORUCABIYFjNFQjA=';

    private const ZAPI_MESSAGE_ID = '3999984263738042930CD6ECDE9VDWSA';

    private const PHONE = '5511999998888';

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'zapi.base_url' => 'https://zapi.example.com',
            'zapi.client_token' => 'zapi-client-token',
            'zapi-lite.base_url' => 'https://zapilite.example.com',
            'zapi-lite.client_token' => 'zapilite-client-token',
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);
    }

    public static function providerProvider(): array
    {
        return [
            'WhatsApp Cloud' => [ProviderEnum::WhatsAppCloud],
            'Z-API' => [ProviderEnum::ZApi],
            'Z-API Lite' => [ProviderEnum::ZApiLite],
            'FunApi' => [ProviderEnum::FunApi],
        ];
    }

    /** Callers detect the capability on what the facade hands them. */
    #[DataProvider('providerProvider')]
    public function test_every_resolvable_client_implements_the_contract(ProviderEnum $provider): void
    {
        $this->assertInstanceOf(ReadReceiptsContract::class, WapiGateway::messages($provider));
    }

    // --- Meta (WhatsApp Cloud API) ---------------------------------------

    public function test_meta_mark_as_read_sends_the_documented_payload(): void
    {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['success' => true], 200)]);

        $result = (new MetaClient())->markMessageAsRead('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, self::WAMID);

        $this->assertSame(['success' => true], $result);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v26.0/PHONE_NUMBER_ID/messages'
            && $request->hasHeader('Authorization', 'Bearer META_TOKEN')
            // Exact body: no destination (the wamid locates the message) and
            // no typing indicator.
            && $request->data() === [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => self::WAMID,
            ]);
    }

    public function test_meta_mark_as_read_with_typing_adds_the_typing_indicator(): void
    {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['success' => true], 200)]);

        $result = (new MetaClient())->markMessageAsReadWithTyping('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, self::WAMID);

        $this->assertSame(['success' => true], $result);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.facebook.com/v26.0/PHONE_NUMBER_ID/messages'
            && $request->data() === [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => self::WAMID,
                'typing_indicator' => ['type' => 'text'],
            ]);
    }

    public static function metaMethodProvider(): array
    {
        return [
            'markMessageAsRead' => ['markMessageAsRead'],
            'markMessageAsReadWithTyping' => ['markMessageAsReadWithTyping'],
        ];
    }

    /** Meta's error object comes back intact, code included, so callers can branch on it. */
    #[DataProvider('metaMethodProvider')]
    public function test_meta_returns_its_error_object_when_meta_refuses(string $method): void
    {
        $error = [
            'message' => '(#131009) Parameter value is not valid',
            'type' => 'OAuthException',
            'code' => 131009,
            'fbtrace_id' => 'TRACE',
        ];
        Http::fake(['https://graph.facebook.com/*' => Http::response(['error' => $error], 400)]);

        $result = (new MetaClient())->{$method}('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, 'wamid.UNKNOWN');

        $this->assertSame(['error' => $error], $result);
    }

    /** Only `success: true` confirms. A 2xx without it is not taken as done. */
    #[DataProvider('metaMethodProvider')]
    public function test_meta_requires_success_true_to_report_success(string $method): void
    {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['success' => false], 200)]);

        $result = (new MetaClient())->{$method}('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, self::WAMID);

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('success', $result);
    }

    #[DataProvider('metaMethodProvider')]
    public function test_meta_never_throws_on_a_connection_failure(string $method): void
    {
        Http::fake(['https://graph.facebook.com/*' => Http::failedConnection()]);

        $result = (new MetaClient())->{$method}('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, self::WAMID);

        $this->assertArrayHasKey('error', $result);
        $this->assertIsString($result['error']);
    }

    #[DataProvider('metaMethodProvider')]
    public function test_meta_refuses_a_blank_message_id_without_a_request(string $method): void
    {
        Http::fake();

        $result = (new MetaClient())->{$method}('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, '  ');

        $this->assertArrayHasKey('error', $result);
        Http::assertNothingSent();
    }

    // --- Z-API and Z-API Lite ---------------------------------------------

    public static function zapiFamilyProvider(): array
    {
        return [
            'Z-API' => [ZApiClient::class, 'https://zapi.example.com', 'zapi-client-token'],
            'Z-API Lite' => [ZApiLiteClient::class, 'https://zapilite.example.com', 'zapilite-client-token'],
        ];
    }

    #[DataProvider('zapiFamilyProvider')]
    public function test_zapi_mark_as_read_posts_read_message(string $client, string $baseUrl, string $clientToken): void
    {
        Http::fake(["{$baseUrl}/*" => Http::response(['value' => true], 200)]);

        $result = (new $client())->markMessageAsRead('UID', 'TOKEN', self::PHONE, self::ZAPI_MESSAGE_ID);

        $this->assertSame(['success' => true], $result);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === "{$baseUrl}/instances/UID/token/TOKEN/read-message"
            && $request->hasHeader('Client-Token', $clientToken)
            && $request->data() === ['phone' => self::PHONE, 'messageId' => self::ZAPI_MESSAGE_ID]);
    }

    /** Groups are read with their group id as `phone`, as Z-API documents. */
    public function test_zapi_mark_as_read_works_on_a_group_chat(): void
    {
        Http::fake(['https://zapi.example.com/*' => Http::response(['value' => true], 200)]);

        $result = (new ZApiClient())->markMessageAsRead('UID', 'TOKEN', '120363019502650977-group', self::ZAPI_MESSAGE_ID);

        $this->assertSame(['success' => true], $result);
        Http::assertSent(fn (Request $request) => ($request->data()['phone'] ?? null) === '120363019502650977-group');
    }

    /** An older version of Z-API's page documented 204 No Content. */
    public function test_zapi_mark_as_read_accepts_an_empty_success(): void
    {
        Http::fake(['https://zapi.example.com/*' => Http::response('', 204)]);

        $this->assertSame(['success' => true], (new ZApiClient())->markMessageAsRead('UID', 'TOKEN', self::PHONE, self::ZAPI_MESSAGE_ID));
    }

    public function test_zapi_mark_as_read_reports_value_false_as_an_error(): void
    {
        Http::fake(['https://zapi.example.com/*' => Http::response(['value' => false], 200)]);

        $result = (new ZApiClient())->markMessageAsRead('UID', 'TOKEN', self::PHONE, self::ZAPI_MESSAGE_ID);

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('success', $result);
    }

    /** Provider errors go through the same formatError() as every other z-api call. */
    public function test_zapi_mark_as_read_formats_a_provider_error(): void
    {
        Http::fake(['https://zapi.example.com/*' => Http::response(['error' => 'You need to be connected with whatsapp'], 400)]);

        $result = (new ZApiClient())->markMessageAsRead('UID', 'TOKEN', self::PHONE, self::ZAPI_MESSAGE_ID);

        $this->assertSame(['error' => 'disconnected_device'], $result);
    }

    /**
     * The z-api URL carries the instance token, and a connection exception
     * quotes the URL. The returned error must not leak it.
     */
    public function test_zapi_mark_as_read_never_throws_and_redacts_the_token(): void
    {
        Http::fake(['https://zapi.example.com/*' => Http::failedConnection()]);

        $result = (new ZApiClient())->markMessageAsRead('UID', 'SECRET-INSTANCE-TOKEN', self::PHONE, self::ZAPI_MESSAGE_ID);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringNotContainsString('SECRET-INSTANCE-TOKEN', $result['error']);
        $this->assertStringContainsString('/token/***/', $result['error']);
    }

    public function test_zapi_mark_as_read_needs_a_chat_and_a_message_id(): void
    {
        Http::fake();

        $this->assertArrayHasKey('error', (new ZApiClient())->markMessageAsRead('UID', 'TOKEN', '', self::ZAPI_MESSAGE_ID));
        $this->assertArrayHasKey('error', (new ZApiClient())->markMessageAsRead('UID', 'TOKEN', self::PHONE, ' '));
        Http::assertNothingSent();
    }

    // --- Unsupported operations ---------------------------------------------

    public static function unsupportedProvider(): array
    {
        return [
            'Z-API typing' => [ZApiClient::class, 'markMessageAsReadWithTyping', 'Z-API'],
            'Z-API Lite typing' => [ZApiLiteClient::class, 'markMessageAsReadWithTyping', 'Z-API Lite'],
            'FunApi typing' => [FunapiClient::class, 'markMessageAsReadWithTyping', 'FunApi'],
            'FunApi read' => [FunapiClient::class, 'markMessageAsRead', 'FunApi'],
        ];
    }

    /**
     * A clear refusal that names the provider, and no request at all. QR
     * typing must not mark the message as read as a side effect either.
     */
    #[DataProvider('unsupportedProvider')]
    public function test_unsupported_operations_refuse_without_a_request(string $client, string $method, string $providerName): void
    {
        Http::fake();

        $result = (new $client())->{$method}('UID', 'TOKEN', self::PHONE, self::ZAPI_MESSAGE_ID);

        $this->assertSame(ReadReceiptsContract::UNSUPPORTED, $result['error']);
        $this->assertStringStartsWith($providerName . ' ', $result['message']);
        Http::assertNothingSent();
    }

    /** The pre-existing method keeps its silent success on QR (compatibility). */
    public function test_send_typing_indicator_on_zapi_is_unchanged(): void
    {
        Http::fake();

        $this->assertSame(['success' => true], (new ZApiClient())->sendTypingIndicator('UID', 'TOKEN', self::ZAPI_MESSAGE_ID));
        Http::assertNothingSent();
    }
}
