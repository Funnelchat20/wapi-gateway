<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\ZApiLiteClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `sendText` builds its payload from an explicit allowlist of option keys, so
 * a quote option that is not wired through is dropped silently — the message
 * still sends, just without the reply relation. These tests pin the wire field
 * (`messageId`) for the providers that honor it, and pin the deliberate no-op
 * for the ones that do not, so the gap stays visible instead of looking like
 * an oversight.
 */
class SendTextQuotedReplyTest extends TestCase
{
    private const QUOTED_ID = '3999984263738042930CD6ECDE9VDWSA';

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
            'zapilite.base_url' => 'https://zapilite.example.com',
            'zapilite.client_token' => 'zapilite-client-token',
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);

        Http::fake([
            '*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1'], 200),
        ]);
    }

    /**
     * @return array<string, array{0: callable}>
     */
    public static function quotingProviders(): array
    {
        return [
            'zapi' => [fn() => new ZApiClient()],
            'zapilite' => [fn() => new ZApiLiteClient()],
            'funapi' => [fn() => new FunapiClient()],
        ];
    }

    #[DataProvider('quotingProviders')]
    public function test_send_text_forwards_message_id_as_the_quote_target(callable $make): void
    {
        $make()->sendText('UID', 'TOKEN', '120363019502650977-group', 'Respondiendo', [
            'messageId' => self::QUOTED_ID,
        ]);

        Http::assertSent(fn($request) => str_contains($request->url(), 'send-text')
            && ($request->data()['messageId'] ?? null) === self::QUOTED_ID);
    }

    #[DataProvider('quotingProviders')]
    public function test_send_text_omits_message_id_when_not_quoting(callable $make): void
    {
        $make()->sendText('UID', 'TOKEN', '5491100000000', 'Mensaje suelto');

        Http::assertSent(fn($request) => str_contains($request->url(), 'send-text')
            && ! array_key_exists('messageId', $request->data()));
    }

    /**
     * A nullable quote target is expected to be passed straight through by
     * callers; null/empty/whitespace must not reach the wire as a quote, or
     * z-api rejects the send instead of treating it as a plain message.
     */
    #[DataProvider('quotingProviders')]
    public function test_send_text_treats_blank_message_id_as_no_quote(callable $make): void
    {
        foreach ([null, '', '   '] as $blank) {
            Http::fake([
                '*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1'], 200),
            ]);

            $make()->sendText('UID', 'TOKEN', '5491100000000', 'Mensaje suelto', ['messageId' => $blank]);

            Http::assertSent(fn($request) => str_contains($request->url(), 'send-text')
                && ! array_key_exists('messageId', $request->data()));
        }
    }

    public function test_send_text_coerces_a_non_string_message_id(): void
    {
        (new ZApiClient())->sendText('UID', 'TOKEN', '5491100000000', 'Respondiendo', [
            'messageId' => 12345,
        ]);

        Http::assertSent(fn($request) => str_contains($request->url(), 'send-text')
            && ($request->data()['messageId'] ?? null) === '12345');
    }

    /**
     * Meta is deliberately NOT wired: WhatsApp Cloud API needs `context.message_id`
     * and has no group support, the only consumer of quoting so far. Pinned so the
     * omission reads as a decision, and so wiring it later trips this test.
     */
    public function test_meta_ignores_the_quote_option(): void
    {
        (new MetaClient())->sendText('WABA-ID', 'TOKEN', '5491100000000', 'Respondiendo', [
            'messageId' => self::QUOTED_ID,
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return ! array_key_exists('messageId', $data) && ! array_key_exists('context', $data);
        });
    }
}
