<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\ZApiLiteClient;
use Funnelchat\WapiGateway\Contracts\BlockUsersContract;
use Funnelchat\WapiGateway\Enums\ProviderEnum;
use Funnelchat\WapiGateway\Gateway\GatewayManager;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;

/**
 * BlockUsersContract on the Z-API family. Z-API and Z-API Lite hit
 * `contacts/modify-blocked`; Funapi's server mounts `modify-blocked` at the
 * instance root. All three answer in Meta's `block_users` shape.
 */
class ZApiFamilyBlockUsersTest extends TestCase
{
    private const PHONE = '5491123456789';
    private const OTHER_PHONE = '5215512345678';

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['zapi' => 'https://zapi.test', 'zapi-lite' => 'https://lite.test', 'funapi' => 'https://funapi.test'] as $prefix => $base) {
            config(["$prefix.base_url" => $base, "$prefix.client_token" => "CT-$prefix"]);
        }
    }

    /** @return array<string, array{0: class-string<ZApiClient>, 1: string, 2: string, 3: string}> */
    public static function providers(): array
    {
        return [
            'z-api' => [ZApiClient::class, 'https://zapi.test/instances/UID/token/TOKEN/contacts/modify-blocked', 'CT-zapi', 'zapi'],
            'z-api lite' => [ZApiLiteClient::class, 'https://lite.test/instances/UID/token/TOKEN/contacts/modify-blocked', 'CT-zapi-lite', 'zapi-lite'],
            'funapi' => [FunapiClient::class, 'https://funapi.test/instances/UID/token/TOKEN/modify-blocked', 'CT-funapi', 'funapi'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function test_block_posts_phone_and_action_to_the_provider_endpoint(string $client, string $url, string $clientToken): void
    {
        Http::fake(['*' => Http::response(['value' => true], 200)]);

        $result = (new $client())->blockUsers('UID', 'TOKEN', ['+' . self::PHONE]);

        Http::assertSent(fn($request) => $request->method() === 'POST'
            && $request->url() === $url
            && $request->hasHeader('Client-Token', $clientToken)
            && $request->data() === ['phone' => self::PHONE, 'action' => 'block']);
        $this->assertSame(['block_users' => ['added_users' => [['input' => '+' . self::PHONE, 'wa_id' => self::PHONE]]]], $result);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function test_unblock_sends_the_unblock_action_and_reports_removed_users(string $client, string $url): void
    {
        Http::fake(['*' => Http::response(['value' => true], 200)]);

        $result = (new $client())->unblockUsers('UID', 'TOKEN', [self::PHONE]);

        Http::assertSent(fn($request) => $request->url() === $url
            && $request->data() === ['phone' => self::PHONE, 'action' => 'unblock']);
        $this->assertSame([['input' => self::PHONE, 'wa_id' => self::PHONE]], $result['block_users']['removed_users']);
        $this->assertArrayNotHasKey('error', $result);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function test_several_users_make_one_request_each_and_keep_failures_apart(string $client): void
    {
        Http::fake(function ($request) {
            return $request->data()['phone'] === self::OTHER_PHONE
                ? Http::response(['error' => 'Not connected'], 400)
                : Http::response(['value' => true], 200);
        });

        $result = (new $client())->blockUsers('UID', 'TOKEN', [self::PHONE, self::OTHER_PHONE]);

        Http::assertSentCount(2);
        $this->assertSame([['input' => self::PHONE, 'wa_id' => self::PHONE]], $result['block_users']['added_users']);
        $this->assertSame(self::OTHER_PHONE, $result['block_users']['failed_users'][0]['input']);
        $this->assertSame(400, $result['block_users']['failed_users'][0]['errors'][0]['code']);
        $this->assertArrayHasKey('error', $result);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function test_a_provider_error_body_on_a_2xx_still_counts_as_failed(string $client): void
    {
        Http::fake(['*' => Http::response(['error' => 'Phone is not on WhatsApp'], 200)]);

        $result = (new $client())->blockUsers('UID', 'TOKEN', [self::PHONE]);

        $this->assertSame([], $result['block_users']['added_users']);
        $this->assertCount(1, $result['block_users']['failed_users']);
        $this->assertNotEmpty($result['error']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function test_a_lid_is_sent_as_is_and_unblock_uses_it_too(string $client): void
    {
        Http::fake(['*' => Http::response(['value' => true], 200)]);

        $block = (new $client())->blockUsers('UID', 'TOKEN', ['987654321012@lid']);
        $unblock = (new $client())->unblockUsers('UID', 'TOKEN', ['987654321012@lid']);

        Http::assertSent(fn($request) => $request->data() === ['phone' => '987654321012@lid', 'action' => 'block']);
        Http::assertSent(fn($request) => $request->data() === ['phone' => '987654321012@lid', 'action' => 'unblock']);
        $this->assertSame('987654321012@lid', $block['block_users']['added_users'][0]['wa_id']);
        $this->assertSame('987654321012@lid', $unblock['block_users']['removed_users'][0]['wa_id']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function test_a_malformed_lid_still_fails_locally(string $client): void
    {
        Http::fake();

        foreach (['abc@lid', '12@lid', '123456@s.whatsapp.net'] as $bad) {
            $result = (new $client())->blockUsers('UID', 'TOKEN', [$bad]);

            $this->assertSame('invalid_phone', $result['block_users']['failed_users'][0]['errors'][0]['code'], $bad);
        }

        Http::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function test_a_value_that_is_not_a_phone_fails_locally_without_a_call(string $client): void
    {
        Http::fake();

        foreach (['CO.1021346770783737', 'abc', 'abc123456789', '12345'] as $notAPhone) {
            $result = (new $client())->blockUsers('UID', 'TOKEN', [$notAPhone]);

            $this->assertSame('invalid_phone', $result['block_users']['failed_users'][0]['errors'][0]['code'], $notAPhone);
            $this->assertNotEmpty($result['error']);
        }

        Http::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function test_phone_formatting_separators_are_stripped(string $client): void
    {
        Http::fake(['*' => Http::response(['value' => true], 200)]);

        (new $client())->blockUsers('UID', 'TOKEN', ['+54 (911) 2345-6789']);

        Http::assertSent(fn($request) => $request->data()['phone'] === '5491123456789');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function test_no_call_is_made_with_zero_or_too_many_users(string $client): void
    {
        Http::fake();
        $tooMany = array_map(fn($i) => (string) (5490000000000 + $i), range(1, BlockUsersContract::MAX_USERS_PER_CALL_SEQUENTIAL + 1));

        $this->assertSame(['error' => 'invalid_users_count'], (new $client())->blockUsers('UID', 'TOKEN', []));
        $this->assertSame(['error' => 'invalid_users_count'], (new $client())->unblockUsers('UID', 'TOKEN', $tooMany));

        Http::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function test_a_timeout_throws_instead_of_being_reported_as_a_failed_user(string $client): void
    {
        Http::fake(fn() => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->expectException(ConnectionException::class);

        (new $client())->blockUsers('UID', 'TOKEN', [self::PHONE]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function test_logs_never_contain_the_phone_number(string $client): void
    {
        $logged = [];
        Log::shouldReceive('channel', 'stack')->andReturnSelf();
        $capture = function ($message, $context = []) use (&$logged) {
            $logged[] = json_encode([$message, $context]);
        };
        Log::shouldReceive('info')->andReturnUsing($capture);
        Log::shouldReceive('error')->andReturnUsing($capture);
        Http::fake(['*' => Http::response(['error' => 'Not connected'], 400)]);

        (new $client())->blockUsers('UID', 'TOKEN', [self::PHONE]);

        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::PHONE, $line);
        }
    }

    public function test_gateway_manager_resolves_every_provider_for_block_users(): void
    {
        $manager = $this->app->make(GatewayManager::class);

        $this->assertInstanceOf(ZApiClient::class, $manager->blockUsers(ProviderEnum::ZApi));
        $this->assertInstanceOf(ZApiLiteClient::class, $manager->blockUsers(ProviderEnum::ZApiLite));
        $this->assertInstanceOf(FunapiClient::class, $manager->blockUsers(ProviderEnum::FunApi));
        foreach (ProviderEnum::cases() as $provider) {
            $this->assertInstanceOf(BlockUsersContract::class, $manager->blockUsers($provider));
        }
    }
}
