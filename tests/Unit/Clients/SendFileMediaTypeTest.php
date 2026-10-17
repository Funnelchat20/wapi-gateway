<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * sendFile() with the URL an integrator hands over: signed URLs, URLs without
 * an extension, and the `type` option that names the media type outright.
 * Every case pins the outgoing payload, and the URL in it is always the one
 * given, signature included. A plain URL must build the request it built
 * before the option existed.
 */
class SendFileMediaTypeTest extends TestCase
{
    private const PHONE = '5491100000000';

    private const SIGNED_JPG = 'https://b.s3.amazonaws.com/a/photo.jpg?X-Amz-Expires=300&X-Amz-Signature=abc';

    private const NO_EXTENSION = 'https://cdn.example.com/media/8f3a2b';

    private const INVALID_TYPE = 'Invalid file type: expected one of image, video, audio, document, sticker';

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
            '*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-1', 'id' => 'MSG-1'], 200),
        ]);
    }

    // --- Meta (WhatsApp Cloud API) ---------------------------------------

    private function metaSend(string $fileUrl, array $options = []): array
    {
        return (new MetaClient())->sendFile('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, $fileUrl, $options);
    }

    private function assertMetaPayload(array $expected): void
    {
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->data() === [
            'messaging_product' => 'whatsapp',
            'to' => self::PHONE,
            ...$expected,
        ]);
    }

    public function test_meta_sends_a_signed_url_with_the_extension_in_its_path(): void
    {
        $result = $this->metaSend(self::SIGNED_JPG, ['caption' => 'Hola']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertMetaPayload(['type' => 'image', 'image' => ['link' => self::SIGNED_JPG, 'caption' => 'Hola']]);
    }

    public function test_meta_sends_a_url_without_extension_with_the_type_option(): void
    {
        $result = $this->metaSend(self::NO_EXTENSION, ['type' => 'video']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertMetaPayload(['type' => 'video', 'video' => ['link' => self::NO_EXTENSION]]);
    }

    /** Refused before the typing indicator, which would mark the inbound as read. */
    public function test_meta_refuses_a_url_without_extension_nor_type_before_any_request(): void
    {
        $result = $this->metaSend(self::NO_EXTENSION, ['typing' => ['lastInboundId' => 'wamid.IN']]);

        $this->assertSame(['error' => 'Invalid file extension'], $result);
        Http::assertNothingSent();
    }

    public function test_meta_takes_a_document_extension_from_the_file_name(): void
    {
        $this->metaSend(self::NO_EXTENSION, ['fileName' => 'Contrato.pdf']);

        $this->assertMetaPayload(['type' => 'document', 'document' => ['link' => self::NO_EXTENSION, 'filename' => 'Contrato.pdf']]);
    }

    public function test_meta_reads_an_uppercase_extension(): void
    {
        $this->metaSend('https://cdn.example.com/a/FOTO.JPG');

        $this->assertMetaPayload(['type' => 'image', 'image' => ['link' => 'https://cdn.example.com/a/FOTO.JPG']]);
    }

    public static function metaUnchangedProvider(): array
    {
        return [
            'image' => ['https://cdn.example.com/a/foto.png', [], ['type' => 'image', 'image' => ['link' => 'https://cdn.example.com/a/foto.png']]],
            'document with name and caption' => [
                'https://cdn.example.com/a/doc.pdf',
                ['fileName' => 'doc.pdf', 'caption' => 'Ver'],
                ['type' => 'document', 'document' => ['link' => 'https://cdn.example.com/a/doc.pdf', 'filename' => 'doc.pdf', 'caption' => 'Ver']],
            ],
            'audio' => ['https://cdn.example.com/a/cancion.mp3', [], ['type' => 'audio', 'audio' => ['link' => 'https://cdn.example.com/a/cancion.mp3']]],
            'gif as video' => ['https://cdn.example.com/a/anim.gif', [], ['type' => 'video', 'video' => ['link' => 'https://cdn.example.com/a/anim.gif']]],
            // Worked before through the whole-URL reading, and still does.
            'extension only in the query' => [
                'https://cdn.example.com/download?file=foto.jpg',
                [],
                ['type' => 'image', 'image' => ['link' => 'https://cdn.example.com/download?file=foto.jpg']],
            ],
        ];
    }

    #[DataProvider('metaUnchangedProvider')]
    public function test_meta_payload_for_a_url_that_already_worked_is_unchanged(string $fileUrl, array $options, array $expected): void
    {
        $this->metaSend($fileUrl, $options);

        $this->assertMetaPayload($expected);
    }

    /** The option wins over the extension, and a document still gets its name. */
    public function test_meta_type_option_overrides_the_extension(): void
    {
        $this->metaSend('https://cdn.example.com/a/foto.jpg', ['type' => 'document', 'fileName' => 'foto.jpg']);

        $this->assertMetaPayload(['type' => 'document', 'document' => ['link' => 'https://cdn.example.com/a/foto.jpg', 'filename' => 'foto.jpg']]);
    }

    public function test_meta_refuses_an_invalid_type_before_any_request(): void
    {
        $result = $this->metaSend(self::SIGNED_JPG, ['type' => 'gif', 'typing' => ['lastInboundId' => 'wamid.IN']]);

        $this->assertSame(['error' => self::INVALID_TYPE], $result);
        Http::assertNothingSent();
    }

    public function test_meta_type_works_with_an_uploaded_media_id(): void
    {
        $this->metaSend(self::NO_EXTENSION, ['type' => 'audio', 'mediaId' => 'MEDIA-1']);

        $this->assertMetaPayload(['type' => 'audio', 'audio' => ['id' => 'MEDIA-1']]);
    }

    public static function metaVoiceNoteProvider(): array
    {
        return [
            'signed ogg' => ['https://b.s3.amazonaws.com/a/nota.ogg?X-Amz-Signature=abc', []],
            'no extension, typed, ogg file name' => [self::NO_EXTENSION, ['type' => 'audio', 'fileName' => 'nota.ogg']],
        ];
    }

    /** The OGG/OPUS check reads the same extension the type came from. */
    #[DataProvider('metaVoiceNoteProvider')]
    public function test_meta_voice_note_reads_the_resolved_extension(string $fileUrl, array $options): void
    {
        $this->metaSend($fileUrl, ['voiceNote' => true, ...$options]);

        $this->assertMetaPayload(['type' => 'audio', 'audio' => ['link' => $fileUrl, 'voice' => true]]);
    }

    /** With no extension at all, nothing proves the audio is OGG/OPUS. */
    public function test_meta_refuses_a_voice_note_of_unknown_format(): void
    {
        $result = $this->metaSend(self::NO_EXTENSION, ['type' => 'audio', 'voiceNote' => true]);

        $this->assertSame('invalid_voice_note_file', $result['error']);
        Http::assertNothingSent();
    }

    public static function metaExtensionProvider(): array
    {
        return [
            'jpg' => ['jpg', 'image'], 'jpeg' => ['jpeg', 'image'], 'png' => ['png', 'image'],
            'mp4' => ['mp4', 'video'], '3gp' => ['3gp', 'video'], 'gif' => ['gif', 'video'], 'mov' => ['mov', 'video'],
            'aac' => ['aac', 'audio'], 'amr' => ['amr', 'audio'], 'mp3' => ['mp3', 'audio'], 'm4a' => ['m4a', 'audio'],
            'ogg' => ['ogg', 'audio'], 'opus' => ['opus', 'audio'], 'wav' => ['wav', 'audio'],
            'pdf' => ['pdf', 'document'], 'txt' => ['txt', 'document'], 'doc' => ['doc', 'document'], 'docx' => ['docx', 'document'],
            'xls' => ['xls', 'document'], 'xlsx' => ['xlsx', 'document'], 'ppt' => ['ppt', 'document'], 'pptx' => ['pptx', 'document'],
            'webp' => ['webp', 'sticker'],
        ];
    }

    #[DataProvider('metaExtensionProvider')]
    public function test_meta_maps_every_extension_the_cloud_api_accepts(string $ext, string $type): void
    {
        $fileUrl = "https://cdn.example.com/a/file.{$ext}?sig=1";

        $result = $this->metaSend($fileUrl);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertMetaPayload(['type' => $type, $type => ['link' => $fileUrl]]);
    }

    /** A sticker takes no caption, as before for audio. */
    public function test_meta_sends_a_sticker_without_caption(): void
    {
        $this->metaSend('https://cdn.example.com/a/sticker.webp', ['caption' => 'ignored']);

        $this->assertMetaPayload(['type' => 'sticker', 'sticker' => ['link' => 'https://cdn.example.com/a/sticker.webp']]);
    }

    public function test_meta_button_header_refuses_a_sticker(): void
    {
        $result = (new MetaClient())->sendButtons('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, 'Elegí', [
            ['id' => 'a', 'label' => 'A'],
        ], ['fileUrl' => 'https://cdn.example.com/a/sticker.webp']);

        $this->assertSame(['error' => 'Invalid file extension'], $result);
        Http::assertNothingSent();
    }

    public function test_meta_button_header_reads_the_extension_from_the_path(): void
    {
        $result = (new MetaClient())->sendButtons('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, 'Elegí', [
            ['id' => 'a', 'label' => 'A'],
        ], ['fileUrl' => self::SIGNED_JPG]);

        $this->assertArrayNotHasKey('error', $result);
        Http::assertSent(fn (Request $request) => ($request->data()['interactive']['header'] ?? null) === [
            'type' => 'image',
            'image' => ['link' => self::SIGNED_JPG],
        ]);
    }

    public function test_meta_button_header_still_refuses_a_url_without_extension(): void
    {
        $result = (new MetaClient())->sendButtons('PHONE_NUMBER_ID', 'META_TOKEN', self::PHONE, 'Elegí', [
            ['id' => 'a', 'label' => 'A'],
        ], ['fileUrl' => self::NO_EXTENSION]);

        $this->assertSame(['error' => 'Invalid file extension'], $result);
        Http::assertNothingSent();
    }
}
