<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Enums\ProviderEnum;
use Funnelchat\WapiGateway\Gateway\GatewayManager;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;

/**
 * BlockUsersContract on MetaClient. Fixtures follow the literal shapes in
 * Meta's Block Users docs (success: `added_users` / `removed_users`; partial
 * failure: outer error 139100 wrapping `failed_users[].errors[].code`).
 */
class MetaClientBlockUsersTest extends TestCase
{
    private const PHONE = '5491123456789';
    private const OTHER_PHONE = '5215512345678';
    private const UID = 'PHONE_NUMBER_ID';

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function fakeGraph(array $body, int $status = 200): void
    {
        Http::fake(['https://graph.facebook.com/*' => Http::response($body, $status)]);
    }

    public function test_block_posts_the_exact_body_to_the_block_users_endpoint(): void
    {
        $this->fakeGraph(['messaging_product' => 'whatsapp', 'block_users' => ['added_users' => [['input' => self::PHONE, 'wa_id' => self::PHONE]]]]);

        $result = (new MetaClient())->blockUsers(self::UID, 'TOKEN', [self::PHONE]);

        Http::assertSent(fn($request) => $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v26.0/' . self::UID . '/block_users'
            && $request->data() === [
                'messaging_product' => 'whatsapp',
                'block_users' => [['user' => self::PHONE]],
            ]);
        $this->assertSame(self::PHONE, $result['block_users']['added_users'][0]['wa_id']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_block_accepts_several_users_in_one_call(): void
    {
        $this->fakeGraph(['block_users' => ['added_users' => []]]);

        (new MetaClient())->blockUsers(self::UID, 'TOKEN', [self::PHONE, self::OTHER_PHONE]);

        Http::assertSent(fn($request) => $request->data()['block_users'] === [['user' => self::PHONE], ['user' => self::OTHER_PHONE]]);
    }

    public function test_unblock_uses_delete_with_the_same_body_shape(): void
    {
        $this->fakeGraph(['block_users' => ['removed_users' => [['input' => self::PHONE, 'wa_id' => self::PHONE]]]]);

        $result = (new MetaClient())->unblockUsers(self::UID, 'TOKEN', [self::PHONE]);

        Http::assertSent(fn($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://graph.facebook.com/v26.0/' . self::UID . '/block_users'
            && $request->data() === [
                'messaging_product' => 'whatsapp',
                'block_users' => [['user' => self::PHONE]],
            ]);
        $this->assertSame(self::PHONE, $result['block_users']['removed_users'][0]['wa_id']);
    }

    public function test_partial_failure_keeps_failed_users_and_the_inner_error_code(): void
    {
        $this->fakeGraph([
            'messaging_product' => 'whatsapp',
            'block_users' => [
                'failed_users' => [[
                    'input' => self::PHONE,
                    'errors' => [['code' => 131047, 'message' => 'Re-engagement message', 'error_data' => ['details' => 'Re-engagement message']]],
                ]],
            ],
            'error' => ['message' => '(#139100) Failed to block/unblock some users', 'type' => 'OAuthException', 'code' => 139100],
        ], 400);

        $result = (new MetaClient())->blockUsers(self::UID, 'TOKEN', [self::PHONE]);

        $this->assertSame(139100, $result['error']['code']);
        $this->assertSame(131047, $result['block_users']['failed_users'][0]['errors'][0]['code']);
    }

    public function test_failure_without_a_meta_error_object_still_returns_an_error_key(): void
    {
        $this->fakeGraph([], 500);

        $result = (new MetaClient())->unblockUsers(self::UID, 'TOKEN', [self::PHONE]);

        $this->assertSame('Failed to unblock users', $result['error']);
    }

    public function test_lists_blocked_users_with_pagination_in_the_query_string(): void
    {
        $this->fakeGraph([
            'data' => [['block_users' => [['input' => self::PHONE, 'wa_id' => self::PHONE]]]],
            'paging' => ['cursors' => ['after' => 'CURSOR_AFTER']],
        ]);

        $result = (new MetaClient())->blockedUsers(self::UID, 'TOKEN', ['limit' => 25, 'after' => 'CURSOR_A', 'ignored' => 'x']);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://graph.facebook.com/v26.0/' . self::UID . '/block_users?')
                && $query === ['limit' => '25', 'after' => 'CURSOR_A'];
        });
        $this->assertSame('CURSOR_AFTER', $result['paging']['cursors']['after']);
    }

    public function test_list_failure_returns_an_error_array(): void
    {
        $this->fakeGraph(['error' => ['message' => 'Invalid token', 'code' => 190]], 401);

        $result = (new MetaClient())->blockedUsers(self::UID, 'TOKEN');

        $this->assertSame(190, $result['error']['code']);
    }

    public function test_no_call_is_made_with_zero_users(): void
    {
        Http::fake();

        $this->assertSame(['error' => 'invalid_users_count'], (new MetaClient())->blockUsers(self::UID, 'TOKEN', []));
        $this->assertSame(['error' => 'invalid_users_count'], (new MetaClient())->unblockUsers(self::UID, 'TOKEN', ['', '  ']));

        Http::assertNothingSent();
    }

    public function test_no_call_is_made_over_the_1000_users_limit(): void
    {
        Http::fake();
        $users = array_map(fn($i) => (string) (5490000000000 + $i), range(1, 1001));

        $this->assertSame(['error' => 'invalid_users_count'], (new MetaClient())->blockUsers(self::UID, 'TOKEN', $users));

        Http::assertNothingSent();
    }

    public function test_exactly_1000_users_are_sent(): void
    {
        $this->fakeGraph(['block_users' => ['added_users' => []]]);
        $users = array_map(fn($i) => (string) (5490000000000 + $i), range(1, 1000));

        (new MetaClient())->blockUsers(self::UID, 'TOKEN', $users);

        Http::assertSent(fn($request) => count($request->data()['block_users']) === 1000);
    }

    public function test_logs_never_contain_the_phone_numbers(): void
    {
        $logged = [];
        Log::shouldReceive('channel', 'stack')->andReturnSelf();
        $capture = function ($message, $context = []) use (&$logged) {
            $logged[] = json_encode([$message, $context]);
        };
        Log::shouldReceive('info')->andReturnUsing($capture);
        Log::shouldReceive('error')->andReturnUsing($capture);
        $this->fakeGraph([
            'block_users' => ['failed_users' => [['input' => self::PHONE, 'wa_id' => self::PHONE, 'errors' => [['code' => 131047]]]]],
            'error' => ['code' => 139100, 'message' => 'Failed to block/unblock some users'],
        ], 400);

        (new MetaClient())->blockUsers(self::UID, 'TOKEN', [self::PHONE]);

        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::PHONE, $line);
        }
        $this->assertStringContainsString('131047', implode('', $logged));
    }

    public function test_gateway_manager_resolves_the_meta_client_for_block_users_and_the_list(): void
    {
        $manager = $this->app->make(GatewayManager::class);

        $this->assertInstanceOf(MetaClient::class, $manager->blockUsers(ProviderEnum::WhatsAppCloud));
        $this->assertInstanceOf(MetaClient::class, $manager->blockedUsers());
    }
}
