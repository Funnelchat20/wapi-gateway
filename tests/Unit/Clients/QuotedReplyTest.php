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
 * Send methods build their payload from an explicit allowlist of option keys,
 * so a quote option that is not wired through is dropped silently — the message
 * still sends, just without the reply relation. Nothing surfaces the difference
 * at runtime, which is exactly why the wire field is pinned here per method.
 *
 * The deliberate no-ops are pinned too: they are decisions driven by what the
 * provider endpoint accepts, not oversights, and wiring one later should trip a
 * test rather than quietly change behavior.
 *
 * The wire field is not shared across providers, so each one is pinned on its
 * own shape: z-api/funapi send a flat `messageId`, UAZAPI a flat `replyid`, and
 * Meta a nested `context.message_id`. What the endpoint accepts differs too —
 * z-api cannot quote from send-audio or send-poll/list/buttons, while the same
 * sends quote fine on UAZAPI because they all go through /send/media and
 * /send/menu, both of which document the param.
 */
class QuotedReplyTest extends TestCase
{
    private const QUOTED_ID = '3999984263738042930CD6ECDE9VDWSA';

    private const GROUP = '120363019502650977-group';

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
            // ZApiLiteClient reads the `zapi-lite` prefix; under the old key it
            // silently fell back to the real api.z-api.io base URL.
            'zapi-lite.base_url' => 'https://zapilite.example.com',
            'zapi-lite.client_token' => 'zapilite-client-token',
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);

        // The shipped endpoint map, not a hand-written copy: which UAZAPI URL a
        // send lands on is exactly what decides whether the quote is honored,
        // so a repointed endpoint has to be able to break these tests.
        config(['uazapi' => require dirname(__DIR__, 3) . '/config/uazapi.php']);
        config(['uazapi.base_url' => 'https://uazapi.example.com']);

        $this->fakeOk();
    }

    private function fakeOk(): void
    {
        Http::fake([
            '*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1'], 200),
        ]);
    }

    private function assertQuoted(string $endpoint): void
    {
        Http::assertSent(fn($request) => str_contains($request->url(), $endpoint)
            && ($request->data()['messageId'] ?? null) === self::QUOTED_ID);
    }

    private function assertNotQuoted(string $endpoint): void
    {
        Http::assertSent(fn($request) => str_contains($request->url(), $endpoint)
            && ! array_key_exists('messageId', $request->data()));
    }

    /** UAZAPI names the param `replyid` and takes it on every /send/* used here. */
    private function assertUazapiQuoted(string $endpoint): void
    {
        Http::assertSent(fn($request) => str_contains($request->url(), $endpoint)
            && ($request->data()['replyid'] ?? null) === self::QUOTED_ID);
    }

    private function assertUazapiNotQuoted(string $endpoint): void
    {
        Http::assertSent(fn($request) => str_contains($request->url(), $endpoint)
            && ! array_key_exists('replyid', $request->data()));
    }

    /** Meta carries the quote nested under `context`, never as a flat field. */
    private function assertMetaQuoted(): void
    {
        Http::assertSent(fn($request) => str_contains($request->url(), '/messages')
            && ($request->data()['context']['message_id'] ?? null) === self::QUOTED_ID);
    }

    private function assertMetaNotQuoted(): void
    {
        Http::assertSent(fn($request) => str_contains($request->url(), '/messages')
            && ! array_key_exists('context', $request->data()));
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

    /**
     * Every send method whose z-api endpoint documents the `messageId` param,
     * paired with the endpoint fragment its request URL must contain.
     *
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function quotableSends(): array
    {
        $quote = ['messageId' => self::QUOTED_ID];

        return [
            'sendText' => [fn($c) => $c->sendText('UID', 'TOKEN', self::GROUP, 'Respondiendo', $quote), 'send-text'],
            'sendFile image' => [fn($c) => $c->sendFile('UID', 'TOKEN', self::GROUP, 'https://cdn.example.com/a.jpg', $quote), 'send-image'],
            'sendFile video' => [fn($c) => $c->sendFile('UID', 'TOKEN', self::GROUP, 'https://cdn.example.com/a.mp4', $quote), 'send-video'],
            'sendFile document' => [fn($c) => $c->sendFile('UID', 'TOKEN', self::GROUP, 'https://cdn.example.com/a.pdf', $quote), 'send-document'],
            'sendFile sticker' => [fn($c) => $c->sendFile('UID', 'TOKEN', self::GROUP, 'https://cdn.example.com/a.webp', $quote), 'send-sticker'],
            'sendLocation' => [fn($c) => $c->sendLocation('UID', 'TOKEN', self::GROUP, -34.6, -58.4, $quote), 'send-location'],
            'sendLink' => [fn($c) => $c->sendLink('UID', 'TOKEN', self::GROUP, 'Mirá esto', 'https://example.com', $quote), 'send-link'],
            'sendContact' => [fn($c) => $c->sendContact('UID', 'TOKEN', self::GROUP, 'Ada', '5491100000000', $quote), 'send-contact'],
            'sendPtv' => [fn($c) => $c->sendPtv('UID', 'TOKEN', self::GROUP, 'https://cdn.example.com/a.mp4', $quote), 'send-ptv'],
        ];
    }

    /**
     * Send methods whose z-api endpoint does NOT document `messageId`. The param
     * is withheld rather than sent and hoped to be ignored, so an unrelated send
     * can never fail because of a quote the endpoint could not honor anyway.
     *
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function nonQuotableSends(): array
    {
        $quote = ['messageId' => self::QUOTED_ID];

        return [
            'sendFile audio' => [fn($c) => $c->sendFile('UID', 'TOKEN', self::GROUP, 'https://cdn.example.com/a.mp3', $quote), 'send-audio'],
            'sendPoll' => [fn($c) => $c->sendPoll('UID', 'TOKEN', self::GROUP, '¿Cuál?', ['A', 'B'], $quote), 'send-poll'],
            'sendOptionList' => [fn($c) => $c->sendOptionList('UID', 'TOKEN', self::GROUP, 'Elegí', 'Ver', [], $quote), 'send-option-list'],
        ];
    }

    #[DataProvider('quotableSends')]
    public function test_zapi_forwards_the_quote_target(callable $send, string $endpoint): void
    {
        $send(new ZApiClient());

        $this->assertQuoted($endpoint);
    }

    #[DataProvider('quotableSends')]
    public function test_funapi_forwards_the_quote_target(callable $send, string $endpoint): void
    {
        $send(new FunapiClient());

        $this->assertQuoted($endpoint);
    }

    /** ZApiLite inherits every send method from ZApiClient — no overrides. */
    #[DataProvider('quotableSends')]
    public function test_zapilite_inherits_the_quote_target(callable $send, string $endpoint): void
    {
        $send(new ZApiLiteClient());

        $this->assertQuoted($endpoint);
    }

    #[DataProvider('nonQuotableSends')]
    public function test_zapi_withholds_the_quote_where_the_endpoint_rejects_it(callable $send, string $endpoint): void
    {
        $send(new ZApiClient());

        $this->assertNotQuoted($endpoint);
    }

    #[DataProvider('nonQuotableSends')]
    public function test_funapi_withholds_the_quote_where_the_endpoint_rejects_it(callable $send, string $endpoint): void
    {
        $send(new FunapiClient());

        $this->assertNotQuoted($endpoint);
    }

    /**
     * `sendFile` picks its endpoint from the file extension, so the audio carve-out
     * has to be per-call, not per-client: the same client instance must quote an
     * image and skip the quote on an audio.
     */
    public function test_send_file_quotes_an_image_but_not_an_audio_on_the_same_client(): void
    {
        $client = new ZApiClient();
        $quote = ['messageId' => self::QUOTED_ID];

        $client->sendFile('UID', 'TOKEN', self::GROUP, 'https://cdn.example.com/a.jpg', $quote);
        $client->sendFile('UID', 'TOKEN', self::GROUP, 'https://cdn.example.com/a.mp3', $quote);

        $this->assertQuoted('send-image');
        $this->assertNotQuoted('send-audio');
    }

    #[DataProvider('quotingProviders')]
    public function test_send_text_omits_message_id_when_not_quoting(callable $make): void
    {
        $make()->sendText('UID', 'TOKEN', '5491100000000', 'Mensaje suelto');

        $this->assertNotQuoted('send-text');
    }

    /**
     * A nullable quote target is expected to be passed straight through by
     * callers; null/empty/whitespace must not reach the wire as a quote, or
     * z-api rejects the send instead of treating it as a plain message.
     *
     * @param mixed $blank
     */
    #[DataProvider('blankQuoteTargets')]
    public function test_blank_message_id_is_treated_as_no_quote($blank): void
    {
        (new ZApiClient())->sendText('UID', 'TOKEN', '5491100000000', 'Mensaje suelto', ['messageId' => $blank]);

        $this->assertNotQuoted('send-text');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function blankQuoteTargets(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace' => ['   '],
            'array' => [[]],
        ];
    }

    public function test_a_non_string_message_id_is_coerced(): void
    {
        (new ZApiClient())->sendText('UID', 'TOKEN', '5491100000000', 'Respondiendo', ['messageId' => 12345]);

        Http::assertSent(fn($request) => str_contains($request->url(), 'send-text')
            && ($request->data()['messageId'] ?? null) === '12345');
    }

    /**
     * The quoted message's type is irrelevant to the provider — the id is opaque,
     * so quoting an image and replying with text is the same wire call as quoting
     * a text. Pinned because it is the motivating product case.
     */
    public function test_quoting_is_agnostic_to_the_quoted_message_type(): void
    {
        (new ZApiClient())->sendText('UID', 'TOKEN', self::GROUP, 'Linda foto', [
            'messageId' => self::QUOTED_ID,
        ]);

        $this->assertQuoted('send-text');
    }

    /**
     * Every UAZAPI send whose endpoint documents `replyid`, paired with the URL
     * fragment the request must contain. Audio is in the quotable set on purpose:
     * it ships through /send/media like every other file, so z-api's send-audio
     * carve-out has no counterpart here.
     *
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function uazapiQuotableSends(): array
    {
        $quote = ['messageId' => self::QUOTED_ID];

        return [
            'sendText' => [fn($c) => $c->sendText('UID', 'TOKEN', self::GROUP, 'Respondiendo', $quote), '/send/text'],
            'sendFile image' => [fn($c) => $c->sendFile('UID', 'TOKEN', self::GROUP, 'https://cdn.example.com/a.jpg', $quote), '/send/media'],
            'sendFile audio' => [fn($c) => $c->sendFile('UID', 'TOKEN', self::GROUP, 'https://cdn.example.com/a.mp3', $quote), '/send/media'],
            'sendFile document' => [fn($c) => $c->sendFile('UID', 'TOKEN', self::GROUP, 'https://cdn.example.com/a.pdf', $quote), '/send/media'],
            'sendLocation' => [fn($c) => $c->sendLocation('UID', 'TOKEN', self::GROUP, -34.6, -58.4, $quote), '/send/location'],
            'sendContact' => [fn($c) => $c->sendContact('UID', 'TOKEN', self::GROUP, 'Ada', '5491100000000', $quote), '/send/contact'],
            'sendLink' => [fn($c) => $c->sendLink('UID', 'TOKEN', self::GROUP, 'Mirá esto', 'https://example.com', $quote), '/send/text'],
            'sendButtons' => [fn($c) => $c->sendButtons('UID', 'TOKEN', self::GROUP, 'Elegí', [['id' => 'b1', 'label' => 'Sí']], $quote), '/send/menu'],
            'sendButtonLink' => [fn($c) => $c->sendButtonLink('UID', 'TOKEN', self::GROUP, 'Mirá', 'https://example.com', 'Abrir', $quote), '/send/menu'],
            'sendOptionList' => [fn($c) => $c->sendOptionList('UID', 'TOKEN', self::GROUP, 'Elegí', 'Ver', [['id' => 'o1', 'title' => 'Uno']], $quote), '/send/menu'],
            'sendPoll' => [fn($c) => $c->sendPoll('UID', 'TOKEN', self::GROUP, '¿Cuál?', ['A', 'B'], $quote), '/send/menu'],
        ];
    }

    #[DataProvider('uazapiQuotableSends')]
    public function test_uazapi_forwards_the_quote_target(callable $send, string $endpoint): void
    {
        $send(new UazapiClient());

        $this->assertUazapiQuoted($endpoint);
    }

    public function test_uazapi_omits_replyid_when_not_quoting(): void
    {
        (new UazapiClient())->sendText('UID', 'TOKEN', '5491100000000', 'Mensaje suelto');

        $this->assertUazapiNotQuoted('/send/text');
    }

    /**
     * Same rule as z-api: a nullable quote target must not reach the wire as an
     * empty `replyid`, so callers can pass the field through without branching.
     *
     * @param mixed $blank
     */
    #[DataProvider('blankQuoteTargets')]
    public function test_uazapi_treats_a_blank_message_id_as_no_quote($blank): void
    {
        (new UazapiClient())->sendText('UID', 'TOKEN', '5491100000000', 'Mensaje suelto', ['messageId' => $blank]);

        $this->assertUazapiNotQuoted('/send/text');
    }

    /**
     * `context` is part of the Cloud API's base message properties, so it rides
     * along unchanged whatever `type` the payload declares — one shape for text,
     * media, location, contacts and interactive alike.
     *
     * @return array<string, array{0: callable}>
     */
    public static function metaQuotableSends(): array
    {
        $quote = ['messageId' => self::QUOTED_ID];

        return [
            'sendText' => [fn($c) => $c->sendText('WABA-ID', 'TOKEN', '5491100000000', 'Respondiendo', $quote)],
            'sendFile image' => [fn($c) => $c->sendFile('WABA-ID', 'TOKEN', '5491100000000', 'https://cdn.example.com/a.jpg', $quote)],
            'sendFile audio' => [fn($c) => $c->sendFile('WABA-ID', 'TOKEN', '5491100000000', 'https://cdn.example.com/a.mp3', $quote)],
            'sendFile document' => [fn($c) => $c->sendFile('WABA-ID', 'TOKEN', '5491100000000', 'https://cdn.example.com/a.pdf', $quote)],
            'sendLocation' => [fn($c) => $c->sendLocation('WABA-ID', 'TOKEN', '5491100000000', -34.6, -58.4, $quote)],
            'sendContact' => [fn($c) => $c->sendContact('WABA-ID', 'TOKEN', '5491100000000', 'Ada', '5491100000000', $quote)],
            'sendLink' => [fn($c) => $c->sendLink('WABA-ID', 'TOKEN', '5491100000000', 'Mirá esto', 'https://example.com', $quote)],
            'sendButtons' => [fn($c) => $c->sendButtons('WABA-ID', 'TOKEN', '5491100000000', 'Elegí', [['id' => 'b1', 'label' => 'Sí']], $quote)],
            'sendButtonLink' => [fn($c) => $c->sendButtonLink('WABA-ID', 'TOKEN', '5491100000000', 'Mirá', 'https://example.com', 'Abrir', $quote)],
            'sendOptionList' => [fn($c) => $c->sendOptionList('WABA-ID', 'TOKEN', '5491100000000', 'Elegí', 'Ver', [['id' => 'o1', 'title' => 'Uno']], $quote)],
        ];
    }

    #[DataProvider('metaQuotableSends')]
    public function test_meta_forwards_the_quote_target_as_context(callable $send): void
    {
        $send(new MetaClient());

        $this->assertMetaQuoted();
    }

    public function test_meta_omits_context_when_not_quoting(): void
    {
        (new MetaClient())->sendText('WABA-ID', 'TOKEN', '5491100000000', 'Mensaje suelto');

        $this->assertMetaNotQuoted();
    }

    /**
     * Meta rejects an empty `context.message_id` outright rather than degrading
     * to a plain message, so a blank target must never reach the wire.
     *
     * @param mixed $blank
     */
    #[DataProvider('blankQuoteTargets')]
    public function test_meta_treats_a_blank_message_id_as_no_quote($blank): void
    {
        (new MetaClient())->sendText('WABA-ID', 'TOKEN', '5491100000000', 'Mensaje suelto', ['messageId' => $blank]);

        $this->assertMetaNotQuoted();
    }

    /**
     * The quote never leaks into the flat `messageId` z-api uses: Meta ignores
     * unknown top-level params silently, so a wrong field name would look like
     * a working send while the reply relation quietly disappears.
     */
    public function test_meta_does_not_send_the_flat_zapi_field(): void
    {
        (new MetaClient())->sendText('WABA-ID', 'TOKEN', '5491100000000', 'Respondiendo', [
            'messageId' => self::QUOTED_ID,
        ]);

        Http::assertSent(fn($request) => ! array_key_exists('messageId', $request->data()));
    }
}
