<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Clients\UazapiClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\ZApiLiteClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Forwarding is the one message operation where z-api and funapi do NOT share a
 * wire: both post to forward-message, but the source chat travels as
 * `messagePhone` on z-api and `sourceChat` on funapi. Each client translates
 * from the same argument, so the field names are pinned in both directions —
 * sending the other provider's name is not a silent no-op, it fails the
 * provider's own required-field validation.
 *
 * Meta and Uazapi are pinned as refusals rather than left untested: neither has
 * a forward-by-id operation to wire, so the error is the correct behavior and
 * has to stay that way until a provider grows the capability.
 */
class ForwardMessageTest extends TestCase
{
    private const MESSAGE_ID = '3999984263738042930CD6ECDE9VDWSA';

    private const DESTINATION = '5491100000000';

    private const SOURCE_CHAT = '120363019502650977-group';

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

        Http::fake(['*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1'], 200)]);
    }

    /**
     * @return array<string, array{0: callable}>
     */
    public static function zapiShapedClients(): array
    {
        return [
            'zapi' => [fn() => new ZApiClient()],
            'zapilite' => [fn() => new ZApiLiteClient()],
        ];
    }

    /**
     * @return array<string, array{0: callable}>
     */
    public static function forwardingClients(): array
    {
        return self::zapiShapedClients() + ['funapi' => [fn() => new FunapiClient()]];
    }

    /**
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function refusingClients(): array
    {
        return [
            'uazapi' => [fn() => new UazapiClient(), 'forwardMessage is not supported by UAZAPI provider'],
            'meta' => [fn() => new MetaClient(), 'Not supported'],
        ];
    }

    #[DataProvider('forwardingClients')]
    public function test_forward_posts_to_the_forward_endpoint(callable $make): void
    {
        $make()->forwardMessage('UID', 'TOKEN', self::DESTINATION, self::MESSAGE_ID, self::SOURCE_CHAT);

        Http::assertSent(fn($request) => str_contains($request->url(), 'forward-message')
            && $request->data()['phone'] === self::DESTINATION
            && $request->data()['messageId'] === self::MESSAGE_ID);
    }

    /** z-api names the source chat `messagePhone`. */
    #[DataProvider('zapiShapedClients')]
    public function test_zapi_sends_the_source_chat_as_message_phone(callable $make): void
    {
        $make()->forwardMessage('UID', 'TOKEN', self::DESTINATION, self::MESSAGE_ID, self::SOURCE_CHAT);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['messagePhone'] === self::SOURCE_CHAT
                && ! array_key_exists('sourceChat', $data);
        });
    }

    /**
     * funapi names the same value `sourceChat`. Posting z-api's `messagePhone`
     * here does not degrade quietly — the bridge answers "Source chat is
     * required" — so the absence of the wrong key is asserted, not just the
     * presence of the right one.
     */
    public function test_funapi_sends_the_source_chat_as_source_chat(): void
    {
        (new FunapiClient())->forwardMessage('UID', 'TOKEN', self::DESTINATION, self::MESSAGE_ID, self::SOURCE_CHAT);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['sourceChat'] === self::SOURCE_CHAT
                && ! array_key_exists('messagePhone', $data);
        });
    }

    #[DataProvider('forwardingClients')]
    public function test_delay_message_is_forwarded_when_given(callable $make): void
    {
        $make()->forwardMessage('UID', 'TOKEN', self::DESTINATION, self::MESSAGE_ID, self::SOURCE_CHAT, [
            'delayMessage' => 5,
        ]);

        Http::assertSent(fn($request) => ($request->data()['delayMessage'] ?? null) === 5);
    }

    #[DataProvider('forwardingClients')]
    public function test_delay_message_is_omitted_when_not_given(callable $make): void
    {
        $make()->forwardMessage('UID', 'TOKEN', self::DESTINATION, self::MESSAGE_ID, self::SOURCE_CHAT);

        Http::assertSent(fn($request) => ! array_key_exists('delayMessage', $request->data()));
    }

    /** funapi-only: the bridge accepts an ISO 8601 instant, capped at 60s ahead. */
    public function test_funapi_forwards_the_scheduled_for_option(): void
    {
        (new FunapiClient())->forwardMessage('UID', 'TOKEN', self::DESTINATION, self::MESSAGE_ID, self::SOURCE_CHAT, [
            'scheduledFor' => '2026-09-06T15:30:00Z',
        ]);

        Http::assertSent(fn($request) => ($request->data()['scheduledFor'] ?? null) === '2026-09-06T15:30:00Z');
    }

    public function test_funapi_omits_a_blank_scheduled_for(): void
    {
        (new FunapiClient())->forwardMessage('UID', 'TOKEN', self::DESTINATION, self::MESSAGE_ID, self::SOURCE_CHAT, [
            'scheduledFor' => '   ',
        ]);

        Http::assertSent(fn($request) => ! array_key_exists('scheduledFor', $request->data()));
    }

    /**
     * The bridge's request struct declares `isGroup` and its handler never
     * reads it — it derives nothing from the flag. Pinned so nobody adds it
     * back believing group forwards depend on it.
     */
    public function test_funapi_does_not_send_the_unused_is_group_flag(): void
    {
        (new FunapiClient())->forwardMessage('UID', 'TOKEN', self::SOURCE_CHAT, self::MESSAGE_ID, self::SOURCE_CHAT, [
            'isGroup' => true,
        ]);

        Http::assertSent(fn($request) => ! array_key_exists('isGroup', $request->data()));
    }

    /**
     * Not an unwired option: neither provider has a forward-by-id operation, so
     * the refusal is the honest answer and must not turn into a silent send.
     */
    #[DataProvider('refusingClients')]
    public function test_providers_without_a_forward_operation_refuse_without_calling_out(callable $make, string $error): void
    {
        $result = $make()->forwardMessage('UID', 'TOKEN', self::DESTINATION, self::MESSAGE_ID, self::SOURCE_CHAT);

        $this->assertSame(['error' => $error], $result);
        Http::assertNothingSent();
    }
}
