<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Clients\UazapiClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Companion to SendReactionTest, which owns the z-api family's two-endpoint
 * routing. This file covers what that one cannot: the providers whose wire is
 * shaped differently, and funapi's extra payload field.
 *
 * The contract is one method where a blank emoji withdraws the reaction. That
 * reads as three different things on the wire — a different endpoint on z-api,
 * an empty `text` on Uazapi, an empty `emoji` on Meta — and all three come back
 * 200 whether or not the removal actually took, so each is pinned here.
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
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);

        // The shipped endpoint map, not a hand-written copy: which UAZAPI URL a
        // reaction lands on is part of what is being asserted.
        config(['uazapi' => require dirname(__DIR__, 3) . '/config/uazapi.php']);
        config(['uazapi.base_url' => 'https://uazapi.example.com']);

        Http::fake(['*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1'], 200)]);
    }

    /**
     * Blank in every shape a caller can produce it. Whitespace is included on
     * purpose: it reaches the provider as an invalid emoji rather than as a
     * removal unless the client normalizes it.
     *
     * @return array<string, array{0: string}>
     */
    public static function blankEmojis(): array
    {
        return ['empty string' => [''], 'whitespace' => ['   ']];
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

    /** No second endpoint to route to: the blank changes the payload, not the URL. */
    #[DataProvider('blankEmojis')]
    public function test_uazapi_withdraws_with_an_empty_text_on_the_same_endpoint(string $blank): void
    {
        (new UazapiClient())->sendReaction('UID', 'TOKEN', self::PARTICIPANT, self::MESSAGE_ID, $blank);

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

    /** Meta reads an empty `emoji` as the withdrawal — same type, same endpoint. */
    #[DataProvider('blankEmojis')]
    public function test_meta_withdraws_with_an_empty_emoji(string $blank): void
    {
        (new MetaClient())->sendReaction('WABA-ID', 'TOKEN', self::PARTICIPANT, self::MESSAGE_ID, $blank);

        Http::assertSent(fn($request) => $request->data()['type'] === 'reaction'
            && $request->data()['reaction']['emoji'] === ''
            && $request->data()['reaction']['message_id'] === self::MESSAGE_ID);
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

    /**
     * Withdrawing needs the sender just as much as reacting does — it is the
     * same whatsmeow call with an empty emoji — so the field has to survive the
     * switch to the remove endpoint.
     */
    public function test_funapi_forwards_the_sender_when_withdrawing_too(): void
    {
        (new FunapiClient())->sendReaction('UID', 'TOKEN', self::GROUP, self::MESSAGE_ID, '', [
            'sender' => self::PARTICIPANT,
        ]);

        Http::assertSent(fn($request) => str_contains($request->url(), 'send-remove-reaction')
            && ($request->data()['sender'] ?? null) === self::PARTICIPANT);
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
}
