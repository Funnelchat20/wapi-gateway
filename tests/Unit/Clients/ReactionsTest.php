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
 * Reacting is one operation with four wire shapes: z-api and funapi split it
 * across send-reaction / send-remove-reaction, while Uazapi and Meta use a
 * single call whose emoji field doubles as the removal when left empty. The
 * shapes are pinned per provider because nothing at runtime distinguishes a
 * reaction that never landed from one that did — both come back 200.
 *
 * The blank-emoji guard gets the most coverage here on purpose: it is the one
 * place where forwarding the caller's value verbatim would mean the same call
 * removes a reaction on two providers and errors on the other two.
 */
class ReactionsTest extends TestCase
{
    private const MESSAGE_ID = '3999984263738042930CD6ECDE9VDWSA';

    private const EMOJI = '👍';

    private const GROUP = '120363019502650977-group';

    private const PARTICIPANT = '5491100000000';

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
            // ZApiLiteClient reads the `zapi-lite` prefix, not `zapilite`.
            'zapi-lite.base_url' => 'https://zapilite.example.com',
            'zapi-lite.client_token' => 'zapilite-client-token',
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);

        config(['uazapi' => require dirname(__DIR__, 3) . '/config/uazapi.php']);
        config(['uazapi.base_url' => 'https://uazapi.example.com']);

        Http::fake(['*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1'], 200)]);
    }

    /**
     * z-api's wire, which funapi mirrors field for field.
     *
     * @return array<string, array{0: callable}>
     */
    public static function zapiShapedClients(): array
    {
        return [
            'zapi' => [fn() => new ZApiClient()],
            'zapilite' => [fn() => new ZApiLiteClient()],
            'funapi' => [fn() => new FunapiClient()],
        ];
    }

    /** Every client, since all four implement both operations. */
    public static function allClients(): array
    {
        return self::zapiShapedClients() + [
            'uazapi' => [fn() => new UazapiClient()],
            'meta' => [fn() => new MetaClient()],
        ];
    }

    #[DataProvider('zapiShapedClients')]
    public function test_zapi_shaped_send_reaction_posts_phone_message_id_and_emoji(callable $make): void
    {
        $make()->sendReaction('UID', 'TOKEN', self::PARTICIPANT, self::MESSAGE_ID, self::EMOJI);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), 'send-reaction')
                && $data['phone'] === self::PARTICIPANT
                && $data['messageId'] === self::MESSAGE_ID
                && $data['reaction'] === self::EMOJI;
        });
    }

    /**
     * The removal endpoint takes no emoji: there is only ever one reaction of
     * ours on a message, so naming it would add nothing. Sending `reaction`
     * anyway is pinned as wrong — it is the field the send endpoint validates,
     * and leaking it here would hide a caller passing the wrong operation.
     */
    #[DataProvider('zapiShapedClients')]
    public function test_zapi_shaped_remove_reaction_posts_no_emoji(callable $make): void
    {
        $make()->removeReaction('UID', 'TOKEN', self::PARTICIPANT, self::MESSAGE_ID);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), 'send-remove-reaction')
                && $data['phone'] === self::PARTICIPANT
                && $data['messageId'] === self::MESSAGE_ID
                && ! array_key_exists('reaction', $data);
        });
    }

    /** send-remove-reaction is its own endpoint, not send-reaction with a flag. */
    public function test_remove_reaction_does_not_hit_the_send_endpoint(): void
    {
        (new ZApiClient())->removeReaction('UID', 'TOKEN', self::PARTICIPANT, self::MESSAGE_ID);

        Http::assertSent(fn($request) => str_contains($request->url(), '/send-remove-reaction'));
        Http::assertNotSent(fn($request) => str_ends_with($request->url(), '/send-reaction'));
    }

    #[DataProvider('zapiShapedClients')]
    public function test_zapi_shaped_clients_forward_the_delay_option(callable $make): void
    {
        $client = $make();

        $client->sendReaction('UID', 'TOKEN', self::PARTICIPANT, self::MESSAGE_ID, self::EMOJI, ['delayMessage' => 5]);
        $client->removeReaction('UID', 'TOKEN', self::PARTICIPANT, self::MESSAGE_ID, ['delayMessage' => 5]);

        Http::assertSent(fn($request) => str_contains($request->url(), 'send-reaction')
            && ($request->data()['delayMessage'] ?? null) === 5);
        Http::assertSent(fn($request) => str_contains($request->url(), 'send-remove-reaction')
            && ($request->data()['delayMessage'] ?? null) === 5);
    }

    /**
     * The bridge builds the reaction through whatsmeow, which needs the JID of
     * whoever sent the target message — not the chat it lives in. Those are the
     * same in a 1:1 and differ in a group, which is the motivating case, so a
     * dropped `sender` means the reaction lands on nothing without erroring.
     */
    public function test_funapi_forwards_the_sender_for_a_group_reaction(): void
    {
        (new FunapiClient())->sendReaction('UID', 'TOKEN', self::GROUP, self::MESSAGE_ID, self::EMOJI, [
            'sender' => self::PARTICIPANT,
        ]);

        Http::assertSent(fn($request) => ($request->data()['sender'] ?? null) === self::PARTICIPANT);
    }

    public function test_funapi_omits_a_blank_sender(): void
    {
        (new FunapiClient())->sendReaction('UID', 'TOKEN', self::PARTICIPANT, self::MESSAGE_ID, self::EMOJI, [
            'sender' => '   ',
        ]);

        // Absent, not empty: the bridge rejects a malformed sender outright but
        // falls back to the chat JID when the field is missing.
        Http::assertSent(fn($request) => ! array_key_exists('sender', $request->data()));
    }

    /** z-api resolves the sender server-side; sending the field would be noise. */
    public function test_zapi_does_not_forward_the_funapi_only_sender_option(): void
    {
        (new ZApiClient())->sendReaction('UID', 'TOKEN', self::GROUP, self::MESSAGE_ID, self::EMOJI, [
            'sender' => self::PARTICIPANT,
        ]);

        Http::assertSent(fn($request) => ! array_key_exists('sender', $request->data()));
    }

    public function test_uazapi_reacts_through_the_single_react_endpoint(): void
    {
        (new UazapiClient())->sendReaction('UID', 'TOKEN', self::PARTICIPANT, self::MESSAGE_ID, self::EMOJI);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), '/message/react')
                && $data['number'] === self::PARTICIPANT
                && $data['id'] === self::MESSAGE_ID
                && $data['text'] === self::EMOJI;
        });
    }

    /** UAZAPI models the removal as the same call with an empty `text`. */
    public function test_uazapi_removes_with_an_empty_text(): void
    {
        (new UazapiClient())->removeReaction('UID', 'TOKEN', self::PARTICIPANT, self::MESSAGE_ID);

        Http::assertSent(fn($request) => str_contains($request->url(), '/message/react')
            && $request->data()['text'] === ''
            && $request->data()['id'] === self::MESSAGE_ID);
    }

    public function test_meta_sends_a_reaction_typed_message(): void
    {
        (new MetaClient())->sendReaction('WABA-ID', 'TOKEN', self::PARTICIPANT, self::MESSAGE_ID, self::EMOJI);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), '/messages')
                && $data['type'] === 'reaction'
                && $data['reaction']['message_id'] === self::MESSAGE_ID
                && $data['reaction']['emoji'] === self::EMOJI;
        });
    }

    /** Meta reads an empty `emoji` as "clear it" — same type, same endpoint. */
    public function test_meta_removes_with_an_empty_emoji(): void
    {
        (new MetaClient())->removeReaction('WABA-ID', 'TOKEN', self::PARTICIPANT, self::MESSAGE_ID);

        Http::assertSent(fn($request) => $request->data()['type'] === 'reaction'
            && $request->data()['reaction']['emoji'] === ''
            && $request->data()['reaction']['message_id'] === self::MESSAGE_ID);
    }

    /**
     * The guard that keeps one call from meaning two things. On Meta and Uazapi
     * a blank emoji IS the removal, so forwarding it would delete the user's
     * reaction where the caller asked to add one; on z-api the same value is a
     * server-side error. Refusing it in every client makes the operation mean
     * the same thing everywhere, and does it without a round-trip.
     *
     * @param mixed $blank
     */
    #[DataProvider('blankEmojiCases')]
    public function test_send_reaction_refuses_a_blank_emoji_without_calling_the_provider(callable $make, string $blank): void
    {
        $result = $make()->sendReaction('UID', 'TOKEN', self::PARTICIPANT, self::MESSAGE_ID, $blank);

        $this->assertSame(['error' => 'Reaction emoji is required'], $result);
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function blankEmojiCases(): array
    {
        $cases = [];
        foreach (self::allClients() as $provider => [$make]) {
            foreach (['empty string' => '', 'whitespace' => '   '] as $label => $blank) {
                $cases["$provider / $label"] = [$make, $blank];
            }
        }

        return $cases;
    }

    /** The guard belongs to sendReaction only: removing still sends. */
    #[DataProvider('allClients')]
    public function test_remove_reaction_is_never_blocked_by_the_blank_guard(callable $make): void
    {
        $make()->removeReaction('UID', 'TOKEN', self::PARTICIPANT, self::MESSAGE_ID);

        Http::assertSentCount(1);
    }
}
