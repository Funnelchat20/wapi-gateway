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
 * Reacting and un-reacting are two different Z-API endpoints, and the caller
 * picks between them with nothing but a blank string. That routing is invisible
 * at runtime — a remove that lands on `send-reaction` with an empty emoji would
 * come back 200 and simply do nothing — so both branches are pinned here, along
 * with the absence of the `reaction` key on the remove payload.
 *
 * The unsupported providers are pinned too: they must report an error rather
 * than a silent success, because a reaction that never reaches WhatsApp and
 * reads as sent would show up in the UI as a reaction the group cannot see.
 */
class SendReactionTest extends TestCase
{
    private const MESSAGE_ID = '3999984263738042930CD6ECDE9VDWSA';

    private const GROUP = '120363019502650977-group';

    private const EMOJI = '❤️';

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
            // The config file is `zapi-lite.php`, so this is the prefix
            // ZApiLiteClient actually reads — `zapilite.*` would silently fall
            // back to the hardcoded api.z-api.io default and the host
            // assertions below would not be testing anything.
            'zapi-lite.base_url' => 'https://zapilite.example.com',
            'zapi-lite.client_token' => 'zapilite-client-token',
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);
    }

    private function fakeOk(): void
    {
        Http::fake([
            '*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1'], 200),
        ]);
    }

    /**
     * The z-api family: one implementation on ZApiClient, inherited by the other
     * two. Each must reach its OWN host — a client that silently used the z-api
     * base_url would still pass a payload-only assertion.
     *
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function zapiFamily(): array
    {
        return [
            'zapi' => [fn() => new ZApiClient(), 'https://zapi.example.com'],
            'zapilite' => [fn() => new ZApiLiteClient(), 'https://zapilite.example.com'],
            'funapi' => [fn() => new FunapiClient(), 'https://funapi.example.com'],
        ];
    }

    #[DataProvider('zapiFamily')]
    public function test_it_posts_the_emoji_to_the_send_endpoint_on_the_own_host(callable $make, string $host): void
    {
        $this->fakeOk();

        $make()->sendReaction('UID', 'TOKEN', self::GROUP, self::MESSAGE_ID, self::EMOJI);

        Http::assertSent(fn($request) => $request->url() === "$host/instances/UID/token/TOKEN/send-reaction"
            && $request->data() === [
                'phone' => self::GROUP,
                'messageId' => self::MESSAGE_ID,
                'reaction' => self::EMOJI,
            ]);
    }

    #[DataProvider('zapiFamily')]
    public function test_a_blank_emoji_routes_to_the_remove_endpoint_without_a_reaction_key(callable $make, string $host): void
    {
        $this->fakeOk();

        $make()->sendReaction('UID', 'TOKEN', self::GROUP, self::MESSAGE_ID, '');

        Http::assertSent(fn($request) => $request->url() === "$host/instances/UID/token/TOKEN/send-remove-reaction"
            && $request->data() === [
                'phone' => self::GROUP,
                'messageId' => self::MESSAGE_ID,
            ]);
    }

    /**
     * A UI that sends the emoji it has in state can easily hand over a padded
     * string; treating it as a real reaction would post whitespace as an emoji.
     */
    public function test_a_whitespace_only_emoji_is_treated_as_a_removal(): void
    {
        $this->fakeOk();

        (new ZApiClient())->sendReaction('UID', 'TOKEN', self::GROUP, self::MESSAGE_ID, "  \n ");

        Http::assertSent(fn($request) => str_contains($request->url(), 'send-remove-reaction')
            && ! array_key_exists('reaction', $request->data()));
    }

    public function test_it_forwards_the_client_token_of_the_calling_provider(): void
    {
        $this->fakeOk();

        (new FunapiClient())->sendReaction('UID', 'TOKEN', self::GROUP, self::MESSAGE_ID, self::EMOJI);

        Http::assertSent(fn($request) => $request->hasHeader('Client-Token', 'funapi-client-token'));
    }

    public function test_it_honors_the_delay_option_and_omits_it_otherwise(): void
    {
        $this->fakeOk();

        (new ZApiClient())->sendReaction('UID', 'TOKEN', self::GROUP, self::MESSAGE_ID, self::EMOJI, ['delayMessage' => 5]);

        Http::assertSent(fn($request) => ($request->data()['delayMessage'] ?? null) === 5);

        (new ZApiClient())->sendReaction('UID', 'TOKEN', self::GROUP, self::MESSAGE_ID, self::EMOJI);

        Http::assertSent(fn($request) => str_contains($request->url(), 'send-reaction')
            && ! array_key_exists('delayMessage', $request->data()));
    }

    /**
     * The caller needs the same normalized envelope every other send returns,
     * so a reaction can be tracked (and dequeued) like any outgoing message.
     */
    public function test_a_successful_reaction_returns_the_normalized_send_envelope(): void
    {
        $this->fakeOk();

        $result = (new ZApiClient())->sendReaction('UID', 'TOKEN', self::GROUP, self::MESSAGE_ID, self::EMOJI);

        $this->assertSame(true, $result['sent']);
        $this->assertSame('MSG-1', $result['id']);
        $this->assertSame('ZAAP-1', $result['queueId']);
    }

    /**
     * The reason to surface an error at all is that the caller can tell WHY it
     * failed and show something truthful, so the provider's prose has to arrive
     * already mapped to the classified code the rest of the SDK uses.
     */
    public function test_a_provider_error_comes_back_classified(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'You need to be connected with whatsapp'], 200),
        ]);

        $result = (new ZApiClient())->sendReaction('UID', 'TOKEN', self::GROUP, self::MESSAGE_ID, self::EMOJI);

        $this->assertSame(['error' => 'disconnected_device'], $result);
    }

    public function test_an_http_failure_is_reported_as_an_error_not_as_sent(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'GROUP_FORBIDDEN'], 403),
        ]);

        $result = (new ZApiClient())->sendReaction('UID', 'TOKEN', self::GROUP, self::MESSAGE_ID, self::EMOJI);

        $this->assertArrayNotHasKey('sent', $result);
        $this->assertSame(['error' => 'group_forbidden'], $result);
    }

    /**
     * @return array<string, array{0: callable}>
     */
    public static function unsupportedProviders(): array
    {
        return [
            'meta' => [fn() => new MetaClient()],
            'uazapi' => [fn() => new UazapiClient()],
        ];
    }

    #[DataProvider('unsupportedProviders')]
    public function test_unsupported_providers_report_an_error_and_call_nothing(callable $make): void
    {
        Http::fake();

        $result = $make()->sendReaction('UID', 'TOKEN', self::GROUP, self::MESSAGE_ID, self::EMOJI);

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('sent', $result);
        Http::assertNothingSent();
    }
}
