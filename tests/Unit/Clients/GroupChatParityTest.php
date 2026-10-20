<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Group chat parity additions: voice-note waveform on send-audio, the wider
 * document whitelist and the z-api invite link reset. Funapi refuses what it
 * is not known to support. Outbound edit is covered by production's
 * ZApiClientEditMessageTest / FunapiClientEditMessageTest.
 */
class GroupChatParityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function fakeProviders(array $zapiBody = ['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1']): void
    {
        config([
            'zapi.base_url' => 'https://zapi.example.com',
            'zapi.client_token' => 'zapi-client-token',
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);

        Http::fake([
            'https://zapi.example.com/*' => Http::response($zapiBody, 200),
            'https://funapi.example.com/*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1'], 200),
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
        ]);
    }

    // --- waveform ---------------------------------------------------------

    public static function audioClients(): array
    {
        return [
            'zapi' => [ZApiClient::class, 'zapi.example.com'],
            'funapi' => [FunapiClient::class, 'funapi.example.com'],
        ];
    }

    #[DataProvider('audioClients')]
    public function test_send_audio_forwards_waveform_as_true(string $class, string $host): void
    {
        $this->fakeProviders();

        (new $class())->sendFile('UID', 'TOKEN', '5491100000000', 'https://cdn.example.com/nota.ogg', ['waveform' => [0.1, 0.8, 0.4]]);
        (new $class())->sendFile('UID', 'TOKEN', '5491100000000', 'https://cdn.example.com/nota.mp3', ['waveform' => true]);

        Http::assertSentCount(2);
        Http::assertNotSent(fn($request) => ! (str_contains($request->url(), $host)
            && str_contains($request->url(), '/send-audio')
            && ($request->data()['waveform'] ?? null) === true));
    }

    #[DataProvider('audioClients')]
    public function test_send_audio_forwards_disabled_waveform_as_false(string $class): void
    {
        $this->fakeProviders();

        (new $class())->sendFile('UID', 'TOKEN', '5491100000000', 'https://cdn.example.com/nota.ogg', ['waveform' => []]);

        Http::assertSent(fn($request) => ($request->data()['waveform'] ?? null) === false);
    }

    #[DataProvider('audioClients')]
    public function test_waveform_is_omitted_when_absent_or_not_audio(string $class): void
    {
        $this->fakeProviders();

        (new $class())->sendFile('UID', 'TOKEN', '5491100000000', 'https://cdn.example.com/nota.ogg');
        (new $class())->sendFile('UID', 'TOKEN', '5491100000000', 'https://cdn.example.com/foto.jpg', ['waveform' => true]);

        Http::assertSentCount(2);
        Http::assertNotSent(fn($request) => array_key_exists('waveform', $request->data()));
    }

    // --- documents --------------------------------------------------------

    public static function newDocumentExtensions(): array
    {
        return array_map(fn($ext) => [$ext], ['xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'rar', '7z', 'odt', 'ods', 'odp', 'rtf', 'epub']);
    }

    public static function executableExtensions(): array
    {
        return array_map(fn($ext) => [$ext], ['exe', 'bat', 'cmd', 'msi', 'apk', 'sh', 'js', 'jar', 'scr', 'com', 'vbs', 'ps1', 'dmg']);
    }

    #[DataProvider('newDocumentExtensions')]
    public function test_zapi_sends_new_document_extensions_as_documents(string $ext): void
    {
        $this->fakeProviders();

        $result = (new ZApiClient())->sendFile('UID', 'TOKEN', '5491100000000', "https://cdn.example.com/archivo.$ext", ['fileName' => "archivo.$ext"]);

        $this->assertArrayNotHasKey('error', $result);
        Http::assertSent(fn($request) => str_ends_with($request->url(), "/send-document/$ext")
            && $request->data()['document'] === "https://cdn.example.com/archivo.$ext");
    }

    #[DataProvider('newDocumentExtensions')]
    public function test_funapi_sends_new_document_extensions_as_documents(string $ext): void
    {
        $this->fakeProviders();

        $result = (new FunapiClient())->sendFile('UID', 'TOKEN', '5491100000000', "https://cdn.example.com/archivo.$ext");

        $this->assertArrayNotHasKey('error', $result);
        Http::assertSent(fn($request) => str_ends_with($request->url(), '/send-document')
            && $request->data()['document'] === "https://cdn.example.com/archivo.$ext");
    }

    public static function newMetaDocumentExtensions(): array
    {
        return array_map(fn($ext) => [$ext], ['xls', 'xlsx', 'ppt', 'pptx', 'txt']);
    }

    public static function metaUnsupportedDocumentExtensions(): array
    {
        return array_map(fn($ext) => [$ext], ['csv', 'zip', 'rar', '7z', 'odt', 'ods', 'odp', 'rtf', 'epub']);
    }

    #[DataProvider('metaUnsupportedDocumentExtensions')]
    public function test_meta_rejects_document_types_meta_does_not_support(string $ext): void
    {
        $this->fakeProviders();

        $result = (new MetaClient())->sendFile('PHONE-ID', 'META-TOKEN', '5491100000000', "https://cdn.example.com/archivo.$ext", ['fileName' => "archivo.$ext"]);

        $this->assertSame(['error' => 'Invalid file extension'], $result);
        Http::assertNothingSent();
    }

    public function test_meta_document_extensions_are_the_meta_supported_set(): void
    {
        $this->assertEqualsCanonicalizing(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'], MetaClient::DOCUMENT_EXTENSIONS);
    }

    #[DataProvider('newMetaDocumentExtensions')]
    public function test_meta_sends_new_document_extensions_as_documents(string $ext): void
    {
        $this->fakeProviders();

        $result = (new MetaClient())->sendFile('PHONE-ID', 'META-TOKEN', '5491100000000', "https://cdn.example.com/archivo.$ext", ['fileName' => "archivo.$ext"]);

        $this->assertArrayNotHasKey('error', $result);
        Http::assertSent(fn($request) => ($request->data()['type'] ?? null) === 'document'
            && ($request->data()['document']['link'] ?? null) === "https://cdn.example.com/archivo.$ext");
    }

    #[DataProvider('executableExtensions')]
    public function test_executables_are_never_sent(string $ext): void
    {
        $this->fakeProviders();
        $url = "https://cdn.example.com/malware.$ext";

        $this->assertSame(['error' => 'Invalid file extension'], (new ZApiClient())->sendFile('UID', 'TOKEN', '5491100000000', $url));
        $this->assertSame(['error' => 'Invalid file extension'], (new FunapiClient())->sendFile('UID', 'TOKEN', '5491100000000', $url));
        $this->assertSame(['error' => 'Invalid file extension'], (new MetaClient())->sendFile('PHONE-ID', 'META-TOKEN', '5491100000000', $url));
        Http::assertNothingSent();
    }

    public function test_existing_mappings_are_unchanged(): void
    {
        $this->fakeProviders();

        (new ZApiClient())->sendFile('UID', 'TOKEN', '5491100000000', 'https://cdn.example.com/a.pdf');
        (new ZApiClient())->sendFile('UID', 'TOKEN', '5491100000000', 'https://cdn.example.com/a.png');
        (new ZApiClient())->sendFile('UID', 'TOKEN', '5491100000000', 'https://cdn.example.com/a.webp');

        Http::assertSent(fn($r) => str_ends_with($r->url(), '/send-document/pdf'));
        Http::assertSent(fn($r) => str_ends_with($r->url(), '/send-image') && isset($r->data()['image']));
        Http::assertSent(fn($r) => str_ends_with($r->url(), '/send-sticker') && isset($r->data()['sticker']));
    }

    // --- invite link reset ------------------------------------------------

    public function test_zapi_reset_group_invitation_link_posts_and_returns_the_new_link(): void
    {
        $this->fakeProviders(['invitationLink' => 'https://chat.whatsapp.com/NEWCODE']);

        $result = (new ZApiClient())->resetGroupInvitationLink('UID', 'TOKEN', '120363000000000000');

        $this->assertSame(['invitationLink' => 'https://chat.whatsapp.com/NEWCODE'], $result);
        Http::assertSent(fn($request) => $request->method() === 'POST'
            && $request->url() === 'https://zapi.example.com/instances/UID/token/TOKEN/redefine-invitation-link/120363000000000000-group'
            && $request->hasHeader('Client-Token', 'zapi-client-token'));
    }

    public function test_zapi_reset_group_invitation_link_surfaces_provider_errors(): void
    {
        config(['zapi.base_url' => 'https://zapi.example.com']);
        Http::fake(['https://zapi.example.com/*' => Http::response(['error' => 'not admin'], 400)]);

        $result = (new ZApiClient())->resetGroupInvitationLink('UID', 'TOKEN', '120363000000000000');

        $this->assertArrayHasKey('error', $result);
    }

    public function test_zapi_reset_group_invitation_link_rejects_a_response_without_link(): void
    {
        $this->fakeProviders(['value' => true]);

        $result = (new ZApiClient())->resetGroupInvitationLink('UID', 'TOKEN', '120363000000000000');

        $this->assertArrayHasKey('error', $result);
    }

    public function test_funapi_rejects_invite_link_reset_without_calling_the_provider(): void
    {
        $this->fakeProviders();

        $result = (new FunapiClient())->resetGroupInvitationLink('UID', 'TOKEN', '120363000000000000');

        $this->assertArrayHasKey('error', $result);
        Http::assertNothingSent();
    }
}
