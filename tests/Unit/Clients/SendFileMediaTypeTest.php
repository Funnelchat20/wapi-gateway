<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Clients\UazapiClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\ZApiLiteClient;
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

        config([
            'zapi.base_url' => 'https://zapi.example.com',
            'zapi.client_token' => 'zapi-client-token',
            'zapi-lite.base_url' => 'https://zapilite.example.com',
            'zapi-lite.client_token' => 'zapilite-client-token',
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);
        // The shipped endpoint map, so a repointed /send/media breaks these tests.
        config(['uazapi' => require dirname(__DIR__, 3) . '/config/uazapi.php']);
        config(['uazapi.base_url' => 'https://uazapi.example.com']);

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

    // --- Z-API and Z-API Lite ---------------------------------------------

    public static function zapiFamilyProvider(): array
    {
        return [
            'Z-API' => [ZApiClient::class, 'https://zapi.example.com', 'Z-API'],
            'Z-API Lite' => [ZApiLiteClient::class, 'https://zapilite.example.com', 'Z-API Lite'],
        ];
    }

    private function assertRequest(string $url, array $data): void
    {
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === $url && $request->data() === $data);
    }

    #[DataProvider('zapiFamilyProvider')]
    public function test_zapi_sends_a_signed_url_with_the_extension_in_its_path(string $client, string $baseUrl): void
    {
        $result = (new $client())->sendFile('UID', 'TOKEN', self::PHONE, self::SIGNED_JPG);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertRequest("{$baseUrl}/instances/UID/token/TOKEN/send-image", [
            'phone' => self::PHONE,
            'image' => self::SIGNED_JPG,
        ]);
    }

    /** The document suffix is the path's extension, never the query's tail. */
    #[DataProvider('zapiFamilyProvider')]
    public function test_zapi_sends_a_signed_document_to_its_path_extension(string $client, string $baseUrl): void
    {
        $fileUrl = 'https://b.s3.amazonaws.com/a/Contrato.PDF?X-Amz-Expires=300&X-Amz-Signature=abc';

        (new $client())->sendFile('UID', 'TOKEN', self::PHONE, $fileUrl, ['fileName' => 'Contrato.PDF']);

        $this->assertRequest("{$baseUrl}/instances/UID/token/TOKEN/send-document/pdf", [
            'phone' => self::PHONE,
            'document' => $fileUrl,
            'fileName' => 'Contrato.PDF',
        ]);
    }

    /** A typed video still gets the automatic async a video extension gets. */
    #[DataProvider('zapiFamilyProvider')]
    public function test_zapi_sends_a_url_without_extension_with_the_type_option(string $client, string $baseUrl): void
    {
        $result = (new $client())->sendFile('UID', 'TOKEN', self::PHONE, self::NO_EXTENSION, ['type' => 'video']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertRequest("{$baseUrl}/instances/UID/token/TOKEN/send-video", [
            'phone' => self::PHONE,
            'video' => self::NO_EXTENSION,
            'async' => true,
        ]);
    }

    #[DataProvider('zapiFamilyProvider')]
    public function test_zapi_refuses_a_url_without_extension_nor_type(string $client): void
    {
        $result = (new $client())->sendFile('UID', 'TOKEN', self::PHONE, self::NO_EXTENSION);

        $this->assertSame(['error' => 'Invalid file extension'], $result);
        Http::assertNothingSent();
    }

    #[DataProvider('zapiFamilyProvider')]
    public function test_zapi_takes_the_document_extension_from_the_file_name(string $client, string $baseUrl): void
    {
        (new $client())->sendFile('UID', 'TOKEN', self::PHONE, self::NO_EXTENSION, ['type' => 'document', 'fileName' => 'Contrato.docx']);

        $this->assertRequest("{$baseUrl}/instances/UID/token/TOKEN/send-document/docx", [
            'phone' => self::PHONE,
            'document' => self::NO_EXTENSION,
            'fileName' => 'Contrato.docx',
        ]);
    }

    /** Without `type`, the file name alone is enough to guess a document. */
    #[DataProvider('zapiFamilyProvider')]
    public function test_zapi_guesses_a_document_from_the_file_name(string $client, string $baseUrl): void
    {
        (new $client())->sendFile('UID', 'TOKEN', self::PHONE, self::NO_EXTENSION, ['fileName' => 'Contrato.pdf']);

        $this->assertRequest("{$baseUrl}/instances/UID/token/TOKEN/send-document/pdf", [
            'phone' => self::PHONE,
            'document' => self::NO_EXTENSION,
            'fileName' => 'Contrato.pdf',
        ]);
    }

    /** send-document/{ext} cannot be built without an extension: refused, never guessed. */
    #[DataProvider('zapiFamilyProvider')]
    public function test_zapi_refuses_a_typed_document_without_any_extension(string $client, string $baseUrl, string $name): void
    {
        $result = (new $client())->sendFile('UID', 'TOKEN', self::PHONE, self::NO_EXTENSION, ['type' => 'document', 'fileName' => 'Contrato']);

        $this->assertSame(
            ['error' => "{$name} needs the file extension to send a document: the URL path has none, and neither has fileName"],
            $result,
        );
        Http::assertNothingSent();
    }

    /** Z-API takes any document extension; a typed one is not limited to the guessing map. */
    #[DataProvider('zapiFamilyProvider')]
    public function test_zapi_sends_a_typed_document_with_an_extension_it_does_not_guess(string $client, string $baseUrl): void
    {
        (new $client())->sendFile('UID', 'TOKEN', self::PHONE, 'https://cdn.example.com/a/planilla.xlsx?sig=1', ['type' => 'document']);

        $this->assertRequest("{$baseUrl}/instances/UID/token/TOKEN/send-document/xlsx", [
            'phone' => self::PHONE,
            'document' => 'https://cdn.example.com/a/planilla.xlsx?sig=1',
        ]);
    }

    #[DataProvider('zapiFamilyProvider')]
    public function test_zapi_reads_an_uppercase_extension(string $client, string $baseUrl): void
    {
        (new $client())->sendFile('UID', 'TOKEN', self::PHONE, 'https://cdn.example.com/a/NOTA.MP3');

        $this->assertRequest("{$baseUrl}/instances/UID/token/TOKEN/send-audio", [
            'phone' => self::PHONE,
            'audio' => 'https://cdn.example.com/a/NOTA.MP3',
        ]);
    }

    #[DataProvider('zapiFamilyProvider')]
    public function test_zapi_refuses_an_invalid_type(string $client): void
    {
        $result = (new $client())->sendFile('UID', 'TOKEN', self::PHONE, self::SIGNED_JPG, ['type' => 'ptt']);

        $this->assertSame(['error' => self::INVALID_TYPE], $result);
        Http::assertNothingSent();
    }

    public static function zapiUnchangedProvider(): array
    {
        return [
            'image' => ['https://cdn.example.com/a/foto.png', [], 'send-image', ['phone' => self::PHONE, 'image' => 'https://cdn.example.com/a/foto.png']],
            'document with name and caption' => [
                'https://cdn.example.com/a/doc.pdf',
                ['fileName' => 'doc.pdf', 'caption' => 'Ver'],
                'send-document/pdf',
                ['phone' => self::PHONE, 'document' => 'https://cdn.example.com/a/doc.pdf', 'fileName' => 'doc.pdf', 'caption' => 'Ver'],
            ],
            'video gets async' => ['https://cdn.example.com/a/clip.mov', [], 'send-video', ['phone' => self::PHONE, 'video' => 'https://cdn.example.com/a/clip.mov', 'async' => true]],
            'gif is a video' => ['https://cdn.example.com/a/anim.gif', ['async' => false], 'send-video', ['phone' => self::PHONE, 'video' => 'https://cdn.example.com/a/anim.gif', 'async' => false]],
            'audio' => ['https://cdn.example.com/a/nota.oga', [], 'send-audio', ['phone' => self::PHONE, 'audio' => 'https://cdn.example.com/a/nota.oga']],
            'sticker' => ['https://cdn.example.com/a/sticker.webp', [], 'send-sticker', ['phone' => self::PHONE, 'sticker' => 'https://cdn.example.com/a/sticker.webp']],
            // Worked before through the whole-URL reading, and still does.
            'extension only in the query' => [
                'https://cdn.example.com/get.php?file=doc.pdf',
                [],
                'send-document/pdf',
                ['phone' => self::PHONE, 'document' => 'https://cdn.example.com/get.php?file=doc.pdf'],
            ],
        ];
    }

    #[DataProvider('zapiUnchangedProvider')]
    public function test_zapi_request_for_a_url_that_already_worked_is_unchanged(string $fileUrl, array $options, string $action, array $data): void
    {
        (new ZApiClient())->sendFile('UID', 'TOKEN', self::PHONE, $fileUrl, $options);

        $this->assertRequest("https://zapi.example.com/instances/UID/token/TOKEN/{$action}", $data);
    }

    // --- FunApi -------------------------------------------------------------

    private const FUNAPI = 'https://funapi.example.com/instances/UID/token/TOKEN/';

    public function test_funapi_sends_a_signed_url_with_the_extension_in_its_path(): void
    {
        $result = (new FunapiClient())->sendFile('UID', 'TOKEN', self::PHONE, self::SIGNED_JPG, ['caption' => 'Hola']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertRequest(self::FUNAPI . 'send-image', [
            'phone' => self::PHONE,
            'image' => self::SIGNED_JPG,
            'caption' => 'Hola',
        ]);
    }

    /** The bridge's endpoints take no suffix, so a typed document needs no extension. */
    public function test_funapi_sends_a_url_without_extension_with_the_type_option(): void
    {
        $result = (new FunapiClient())->sendFile('UID', 'TOKEN', self::PHONE, self::NO_EXTENSION, ['type' => 'document']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertRequest(self::FUNAPI . 'send-document', [
            'phone' => self::PHONE,
            'document' => self::NO_EXTENSION,
        ]);
    }

    public function test_funapi_refuses_a_url_without_extension_nor_type(): void
    {
        $result = (new FunapiClient())->sendFile('UID', 'TOKEN', self::PHONE, self::NO_EXTENSION);

        $this->assertSame(['error' => 'Invalid file extension'], $result);
        Http::assertNothingSent();
    }

    public function test_funapi_guesses_a_document_from_the_file_name(): void
    {
        (new FunapiClient())->sendFile('UID', 'TOKEN', self::PHONE, self::NO_EXTENSION, ['fileName' => 'Contrato.pdf']);

        $this->assertRequest(self::FUNAPI . 'send-document', [
            'phone' => self::PHONE,
            'document' => self::NO_EXTENSION,
            'fileName' => 'Contrato.pdf',
        ]);
    }

    public function test_funapi_reads_an_uppercase_extension(): void
    {
        (new FunapiClient())->sendFile('UID', 'TOKEN', self::PHONE, 'https://cdn.example.com/a/CLIP.MP4');

        $this->assertRequest(self::FUNAPI . 'send-video', [
            'phone' => self::PHONE,
            'video' => 'https://cdn.example.com/a/CLIP.MP4',
            'async' => true,
        ]);
    }

    public function test_funapi_refuses_an_invalid_type(): void
    {
        $result = (new FunapiClient())->sendFile('UID', 'TOKEN', self::PHONE, self::SIGNED_JPG, ['type' => 'file']);

        $this->assertSame(['error' => self::INVALID_TYPE], $result);
        Http::assertNothingSent();
    }

    public static function funapiUnchangedProvider(): array
    {
        return [
            'document with name and caption' => [
                'https://cdn.example.com/a/doc.docx',
                ['fileName' => 'doc.docx', 'caption' => 'Ver'],
                'send-document',
                ['phone' => self::PHONE, 'document' => 'https://cdn.example.com/a/doc.docx', 'fileName' => 'doc.docx', 'caption' => 'Ver'],
            ],
            'video gets async' => ['https://cdn.example.com/a/clip.mp4', [], 'send-video', ['phone' => self::PHONE, 'video' => 'https://cdn.example.com/a/clip.mp4', 'async' => true]],
            'audio' => ['https://cdn.example.com/a/nota.ogg', [], 'send-audio', ['phone' => self::PHONE, 'audio' => 'https://cdn.example.com/a/nota.ogg']],
            'extension only in the query' => [
                'https://cdn.example.com/download?file=foto.jpeg',
                [],
                'send-image',
                ['phone' => self::PHONE, 'image' => 'https://cdn.example.com/download?file=foto.jpeg'],
            ],
        ];
    }

    #[DataProvider('funapiUnchangedProvider')]
    public function test_funapi_request_for_a_url_that_already_worked_is_unchanged(string $fileUrl, array $options, string $action, array $data): void
    {
        (new FunapiClient())->sendFile('UID', 'TOKEN', self::PHONE, $fileUrl, $options);

        $this->assertRequest(self::FUNAPI . $action, $data);
    }

    // --- UAZAPI -------------------------------------------------------------

    private function uazapiSend(string $fileUrl, array $options = []): array
    {
        return (new UazapiClient())->sendFile('UID', 'TOKEN', self::PHONE, $fileUrl, $options);
    }

    private function assertUazapiPayload(array $data): void
    {
        $this->assertRequest('https://uazapi.example.com/send/media', $data);
    }

    public function test_uazapi_sends_a_signed_url_with_the_extension_in_its_path(): void
    {
        $result = $this->uazapiSend(self::SIGNED_JPG, ['caption' => 'Hola']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertUazapiPayload(['number' => self::PHONE, 'type' => 'image', 'file' => self::SIGNED_JPG, 'text' => 'Hola']);
    }

    public function test_uazapi_sends_a_url_without_extension_with_the_type_option(): void
    {
        $result = $this->uazapiSend(self::NO_EXTENSION, ['type' => 'audio']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertUazapiPayload(['number' => self::PHONE, 'type' => 'audio', 'file' => self::NO_EXTENSION]);
    }

    public function test_uazapi_refuses_a_url_without_extension_nor_type(): void
    {
        $result = $this->uazapiSend(self::NO_EXTENSION);

        $this->assertSame(['error' => 'Invalid file extension'], $result);
        Http::assertNothingSent();
    }

    public function test_uazapi_guesses_a_document_from_the_file_name(): void
    {
        $this->uazapiSend(self::NO_EXTENSION, ['fileName' => 'Contrato.pdf']);

        $this->assertUazapiPayload(['number' => self::PHONE, 'type' => 'document', 'file' => self::NO_EXTENSION, 'docName' => 'Contrato.pdf']);
    }

    public function test_uazapi_reads_an_uppercase_extension(): void
    {
        $this->uazapiSend('https://cdn.example.com/a/STICKER.WEBP');

        $this->assertUazapiPayload(['number' => self::PHONE, 'type' => 'sticker', 'file' => 'https://cdn.example.com/a/STICKER.WEBP']);
    }

    public function test_uazapi_refuses_an_invalid_type(): void
    {
        $result = $this->uazapiSend(self::SIGNED_JPG, ['type' => 'myaudio']);

        $this->assertSame(['error' => self::INVALID_TYPE], $result);
        Http::assertNothingSent();
    }

    public static function uazapiUnchangedProvider(): array
    {
        return [
            'gif is an image here' => ['https://cdn.example.com/a/anim.gif', [], ['number' => self::PHONE, 'type' => 'image', 'file' => 'https://cdn.example.com/a/anim.gif']],
            'document with name, caption and delay' => [
                'https://cdn.example.com/a/doc.pdf',
                ['fileName' => 'doc.pdf', 'caption' => 'Ver', 'delayMessage' => 2],
                ['number' => self::PHONE, 'type' => 'document', 'file' => 'https://cdn.example.com/a/doc.pdf', 'text' => 'Ver', 'docName' => 'doc.pdf', 'delay' => 2],
            ],
            'video' => ['https://cdn.example.com/a/clip.avi', [], ['number' => self::PHONE, 'type' => 'video', 'file' => 'https://cdn.example.com/a/clip.avi']],
            'extension only in the query' => [
                'https://cdn.example.com/download?file=nota.opus',
                [],
                ['number' => self::PHONE, 'type' => 'audio', 'file' => 'https://cdn.example.com/download?file=nota.opus'],
            ],
        ];
    }

    #[DataProvider('uazapiUnchangedProvider')]
    public function test_uazapi_request_for_a_url_that_already_worked_is_unchanged(string $fileUrl, array $options, array $data): void
    {
        $this->uazapiSend($fileUrl, $options);

        $this->assertUazapiPayload($data);
    }
}
