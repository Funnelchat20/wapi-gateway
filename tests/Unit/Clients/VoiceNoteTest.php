<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\ZApiLiteClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The `voiceNote` option of sendFile(), with the same name and error codes as
 * wapi's send-file (Funnelchat20/wapi#393). Two things are pinned on every
 * provider. Without the option the payload is byte-identical to before it
 * existed. And every refusal happens before any request, Meta's typing
 * indicator included.
 */
class VoiceNoteTest extends TestCase
{
    private const PHONE = '5491100000000';

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

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
            '*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1', 'id' => 'MSG-1'], 200),
        ]);
    }

    // --- Meta (WhatsApp Cloud API) ---------------------------------------

    public static function oggProvider(): array
    {
        return [
            'ogg' => ['https://cdn.example.com/nota.ogg'],
            'opus' => ['https://cdn.example.com/nota.opus'],
        ];
    }

    #[DataProvider('oggProvider')]
    public function test_meta_sends_an_ogg_audio_as_a_voice_message(string $fileUrl): void
    {
        $result = (new MetaClient())->sendFile('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, $fileUrl, ['voiceNote' => true]);

        $this->assertArrayNotHasKey('error', $result);
        Http::assertSent(fn (Request $request) => $request->data() === [
            'messaging_product' => 'whatsapp',
            'to' => self::PHONE,
            'type' => 'audio',
            'audio' => ['link' => $fileUrl, 'voice' => true],
        ]);
    }

    /** An uploaded media id carries the flag the same way. */
    public function test_meta_voice_note_works_with_an_uploaded_media_id(): void
    {
        (new MetaClient())->sendFile('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, 'https://cdn.example.com/nota.ogg', [
            'voiceNote' => true,
            'mediaId' => 'MEDIA-1',
        ]);

        Http::assertSent(fn (Request $request) => ($request->data()['audio'] ?? null) === ['id' => 'MEDIA-1', 'voice' => true]);
    }

    public static function metaRefusalProvider(): array
    {
        return [
            'mp3 audio' => ['https://cdn.example.com/cancion.mp3'],
            'm4a audio' => ['https://cdn.example.com/nota.m4a'],
            'image' => ['https://cdn.example.com/foto.jpg'],
            'document' => ['https://cdn.example.com/doc.pdf'],
        ];
    }

    /**
     * Meta plays a voice message only for OGG/OPUS. Anything else is refused
     * before the indicator, which would otherwise mark the inbound as read
     * for a send that never happens.
     */
    #[DataProvider('metaRefusalProvider')]
    public function test_meta_refuses_a_voice_note_that_is_not_ogg_before_any_request(string $fileUrl): void
    {
        $result = (new MetaClient())->sendFile('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, $fileUrl, [
            'voiceNote' => true,
            'typing' => ['lastInboundId' => 'wamid.IN'],
        ]);

        $this->assertSame('invalid_voice_note_file', $result['error']);
        $this->assertNotEmpty($result['message']);
        Http::assertNothingSent();
    }

    public static function noVoiceNoteProvider(): array
    {
        return [
            'absent' => [[]],
            'false' => [['voiceNote' => false]],
            'the string "false"' => [['voiceNote' => 'false']],
            'zero' => [['voiceNote' => 0]],
        ];
    }

    #[DataProvider('noVoiceNoteProvider')]
    public function test_meta_audio_payload_is_unchanged_without_the_option(array $options): void
    {
        (new MetaClient())->sendFile('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, 'https://cdn.example.com/nota.ogg', $options);

        Http::assertSent(fn (Request $request) => $request->data() === [
            'messaging_product' => 'whatsapp',
            'to' => self::PHONE,
            'type' => 'audio',
            'audio' => ['link' => 'https://cdn.example.com/nota.ogg'],
        ]);
    }

    /** Without the option a non-ogg audio still goes out as before. */
    public function test_meta_still_sends_an_mp3_without_the_option(): void
    {
        $result = (new MetaClient())->sendFile('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, 'https://cdn.example.com/cancion.mp3');

        $this->assertArrayNotHasKey('error', $result);
        Http::assertSentCount(1);
    }

    // --- Z-API and Z-API Lite ---------------------------------------------

    public static function zapiFamilyProvider(): array
    {
        return [
            'Z-API' => [ZApiClient::class, 'https://zapi.example.com'],
            'Z-API Lite' => [ZApiLiteClient::class, 'https://zapilite.example.com'],
        ];
    }

    #[DataProvider('zapiFamilyProvider')]
    public function test_zapi_sends_waveform_on_send_audio(string $client, string $baseUrl): void
    {
        $result = (new $client())->sendFile('UID', 'TOKEN', self::PHONE, 'https://cdn.example.com/nota.mp3', ['voiceNote' => true]);

        $this->assertArrayNotHasKey('error', $result);
        Http::assertSent(fn (Request $request) => $request->url() === "{$baseUrl}/instances/UID/token/TOKEN/send-audio"
            && $request->data() === [
                'phone' => self::PHONE,
                'audio' => 'https://cdn.example.com/nota.mp3',
                'waveform' => true,
            ]);
    }

    /** "true" from a form counts as yes, the way FILTER_VALIDATE_BOOLEAN reads it. */
    public function test_zapi_reads_a_string_true_as_yes(): void
    {
        (new ZApiClient())->sendFile('UID', 'TOKEN', self::PHONE, 'https://cdn.example.com/nota.ogg', ['voiceNote' => 'true']);

        Http::assertSent(fn (Request $request) => ($request->data()['waveform'] ?? null) === true);
    }

    #[DataProvider('noVoiceNoteProvider')]
    public function test_zapi_audio_payload_is_unchanged_without_the_option(array $options): void
    {
        (new ZApiClient())->sendFile('UID', 'TOKEN', self::PHONE, 'https://cdn.example.com/nota.mp3', $options);

        Http::assertSent(fn (Request $request) => $request->data() === [
            'phone' => self::PHONE,
            'audio' => 'https://cdn.example.com/nota.mp3',
        ]);
    }

    public static function zapiRefusalProvider(): array
    {
        return [
            'Z-API image' => [ZApiClient::class, 'https://cdn.example.com/foto.jpg'],
            'Z-API document' => [ZApiClient::class, 'https://cdn.example.com/doc.pdf'],
            'Z-API video' => [ZApiClient::class, 'https://cdn.example.com/clip.mp4'],
            'Z-API Lite image' => [ZApiLiteClient::class, 'https://cdn.example.com/foto.jpg'],
        ];
    }

    #[DataProvider('zapiRefusalProvider')]
    public function test_zapi_refuses_a_voice_note_that_is_not_audio(string $client, string $fileUrl): void
    {
        $result = (new $client())->sendFile('UID', 'TOKEN', self::PHONE, $fileUrl, ['voiceNote' => true]);

        $this->assertSame('invalid_voice_note_file', $result['error']);
        Http::assertNothingSent();
    }

    // --- FunApi -------------------------------------------------------------

    /** Unverified support is refused out loud, never ignored silently. */
    public function test_funapi_refuses_a_voice_note_without_a_request(): void
    {
        $result = (new FunapiClient())->sendFile('UID', 'TOKEN', self::PHONE, 'https://cdn.example.com/nota.ogg', ['voiceNote' => true]);

        $this->assertSame('unsupported', $result['error']);
        $this->assertStringStartsWith('FunApi ', $result['message']);
        Http::assertNothingSent();
    }

    public function test_funapi_audio_payload_is_unchanged_without_the_option(): void
    {
        (new FunapiClient())->sendFile('UID', 'TOKEN', self::PHONE, 'https://cdn.example.com/nota.ogg');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://funapi.example.com/instances/UID/token/TOKEN/send-audio'
            && $request->data() === [
                'phone' => self::PHONE,
                'audio' => 'https://cdn.example.com/nota.ogg',
            ]);
    }
}
