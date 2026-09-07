<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\UazapiClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\ZApiLiteClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * `GroupsContract::chat()` — single-chat read, added for communities #1238.
 *
 * The group profile picture is NOT in `group-metadata` under any field name
 * (verified across 531,827 production responses); it only ever came from
 * `chats/{id}`, as `profileThumbnail`. These tests pin the URL shape, the
 * verbatim `$chatId` pass-through, and the per-provider behaviour.
 */
class ChatMetadataTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'zapi.base_url' => 'https://api.z-api.io',
            'zapi.client_token' => 'client-token',
            'zapi-lite.base_url' => 'https://lite.z-api.io',
            'zapi-lite.client_token' => 'lite-token',
            'funapi.base_url' => 'https://funapi.example',
            'funapi.client_token' => 'funapi-token',
        ]);
    }

    /** The payload shape is the real one captured from production. */
    private function profilePayload(): array
    {
        return [
            'phone' => '5493764375872-1621087474',
            'name' => 'la familia más top🔝🔝',
            'isGroup' => true,
            'communityId' => null,
            'profileThumbnail' => 'https://pps.whatsapp.net/v/t61.24694-24/300558704_614722140366816_4277823402830034759_n.jpg?ccb=11-4&oe=6AA83A0F',
            'archived' => 'false',
        ];
    }

    public function test_returns_raw_payload_including_profile_thumbnail(): void
    {
        Http::fake([
            'https://api.z-api.io/instances/UID/token/TOKEN/chats/120363000000000000-group' => Http::response($this->profilePayload(), 200),
        ]);

        $result = (new ZApiClient())->chat('UID', 'TOKEN', '120363000000000000-group');

        // Raw pass-through: the caller reads `profileThumbnail` itself. If this
        // ever gets funnelled through a Resource, the picture would be dropped.
        $this->assertSame(
            'https://pps.whatsapp.net/v/t61.24694-24/300558704_614722140366816_4277823402830034759_n.jpg?ccb=11-4&oe=6AA83A0F',
            $result['profileThumbnail']
        );
        $this->assertTrue($result['isGroup']);
        $this->assertArrayNotHasKey('error', $result);
    }

    /**
     * The legacy `{phone}-{timestamp}` uid puts a second hyphen in the path.
     * Verified against the live provider: it resolves fine. 18,007 groups
     * (8.8% of production) use this form, so it must not be mangled.
     */
    public function test_passes_legacy_hyphenated_chat_id_verbatim(): void
    {
        Http::fake([
            'https://api.z-api.io/instances/UID/token/TOKEN/chats/5493764375872-1621087474-group' => Http::response($this->profilePayload(), 200),
        ]);

        $result = (new ZApiClient())->chat('UID', 'TOKEN', '5493764375872-1621087474-group');

        $this->assertArrayNotHasKey('error', $result);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/chats/5493764375872-1621087474-group'));
    }

    /** No `-group` is appended: this method is not group-specific. */
    public function test_does_not_append_any_suffix(): void
    {
        Http::fake([
            'https://api.z-api.io/*' => Http::response(['phone' => '5491100000000', 'isGroup' => false], 200),
        ]);

        (new ZApiClient())->chat('UID', 'TOKEN', '5491100000000');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/chats/5491100000000'));
    }

    /** A group with no picture returns the field empty, not absent-as-error. */
    public function test_empty_thumbnail_is_not_an_error(): void
    {
        Http::fake([
            'https://api.z-api.io/*' => Http::response(['phone' => 'x-group', 'profileThumbnail' => ''], 200),
        ]);

        $result = (new ZApiClient())->chat('UID', 'TOKEN', 'x-group');

        $this->assertSame('', $result['profileThumbnail']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_http_failure_returns_error(): void
    {
        Http::fake(['https://api.z-api.io/*' => Http::response(['error' => 'chat not found'], 404)]);

        $result = (new ZApiClient())->chat('UID', 'TOKEN', 'missing-group');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('profileThumbnail', $result);
    }

    /** A 200 carrying an `error` key is still an error for this provider. */
    public function test_error_key_in_200_returns_error(): void
    {
        Http::fake(['https://api.z-api.io/*' => Http::response(['error' => 'You are not connected.'], 200)]);

        $result = (new ZApiClient())->chat('UID', 'TOKEN', 'x-group');

        $this->assertArrayHasKey('error', $result);
    }

    /**
     * ZApiLite overrides only `$configPrefix`, so it must inherit `chat()` and
     * hit ITS base url with ITS client token. Provider 4 is the one the #1238
     * reproduction ran on, so this is not hypothetical.
     */
    public function test_zapi_lite_inherits_and_uses_its_own_config(): void
    {
        Http::fake(['https://lite.z-api.io/*' => Http::response($this->profilePayload(), 200)]);

        $result = (new ZApiLiteClient())->chat('UID', 'TOKEN', 'x-group');

        $this->assertArrayNotHasKey('error', $result);
        Http::assertSent(
            fn ($request) => str_starts_with($request->url(), 'https://lite.z-api.io/')
                && $request->hasHeader('Client-Token', 'lite-token')
        );
    }

    public function test_funapi_inherits_and_uses_its_own_config(): void
    {
        Http::fake(['https://funapi.example/*' => Http::response($this->profilePayload(), 200)]);

        $result = (new FunapiClient())->chat('UID', 'TOKEN', 'x-group');

        $this->assertArrayNotHasKey('error', $result);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://funapi.example/'));
    }

    /**
     * UAZAPI has no single-chat read. It must say so explicitly rather than
     * return an empty array, which a caller could misread as "this chat has
     * no picture".
     *
     * It is NOT the case that this provider gets the picture for free from
     * `groupMetadata()`: its `group()` returns the payload raw, and the
     * `PictureUrl` -> `image` mapping belongs to `UazapiController`, not to
     * this client.
     */
    public function test_uazapi_reports_unsupported_without_calling_out(): void
    {
        Http::fake();

        $result = (new UazapiClient())->chat('UID', 'TOKEN', 'x-group');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not supported', $result['error']);
        Http::assertNothingSent();
    }
}
