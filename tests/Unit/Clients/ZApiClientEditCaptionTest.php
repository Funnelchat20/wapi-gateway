<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\ZApiLiteClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Editing the caption of media already sent. Z-API names the edit key per
 * endpoint — `editImageMessageId`, `editVideoMessageId`,
 * `editDocumentMessageId` — so the file extension decides both the endpoint
 * and the key.
 */
class ZApiClientEditCaptionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function fakeZApi(): void
    {
        config([
            'zapi.base_url' => 'https://zapi.example.com',
            'zapi.client_token' => 'zapi-client-token',
        ]);

        Http::fake(['https://zapi.example.com/*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-NEW'], 200)]);
    }

    public static function mediaProvider(): array
    {
        return [
            // file url, endpoint it must hit, edit key Z-API expects, media field
            'image' => ['https://cdn.example.com/foto.jpg', 'send-image', 'editImageMessageId', 'image'],
            'video' => ['https://cdn.example.com/clip.mp4', 'send-video', 'editVideoMessageId', 'video'],
            'document' => ['https://cdn.example.com/precios.pdf', 'send-document/pdf', 'editDocumentMessageId', 'document'],
        ];
    }

    #[DataProvider('mediaProvider')]
    public function test_edit_caption_uses_the_key_that_matches_the_file_type(
        string $fileUrl,
        string $endpoint,
        string $editKey,
        string $mediaField,
    ): void {
        $this->fakeZApi();

        (new ZApiClient())->editCaption('UID', 'TOKEN', '120363000000000000-group', 'MSG-1', $fileUrl, 'Precio corregido');

        Http::assertSent(function ($request) use ($endpoint, $editKey, $mediaField, $fileUrl) {
            $data = $request->data();

            return $request->url() === "https://zapi.example.com/instances/UID/token/TOKEN/$endpoint"
                && ($data[$editKey] ?? null) === 'MSG-1'
                && ($data[$mediaField] ?? null) === $fileUrl
                && ($data['caption'] ?? null) === 'Precio corregido';
        });
        Http::assertSentCount(1);
    }

    /**
     * The keys are not interchangeable: sending the text one, or another
     * type's, would be dropped by Z-API and the call would post a second file.
     */
    #[DataProvider('mediaProvider')]
    public function test_edit_caption_sends_no_other_edit_key(string $fileUrl, string $endpoint, string $editKey): void
    {
        $this->fakeZApi();

        (new ZApiClient())->editCaption('UID', 'TOKEN', '5491100000000', 'MSG-1', $fileUrl, 'Precio corregido');

        $others = array_diff(['editMessageId', 'editImageMessageId', 'editVideoMessageId', 'editDocumentMessageId'], [$editKey]);

        Http::assertSent(function ($request) use ($others) {
            foreach ($others as $key) {
                if (array_key_exists($key, $request->data())) return false;
            }

            return true;
        });
    }

    /** `messageId` is the quote option and must never carry the edit. */
    public function test_edit_caption_does_not_quote_the_original(): void
    {
        $this->fakeZApi();

        (new ZApiClient())->editCaption('UID', 'TOKEN', '5491100000000', 'MSG-1', 'https://cdn.example.com/foto.jpg', 'Nueva');

        Http::assertSent(fn ($request) => ! array_key_exists('messageId', $request->data()));
    }

    /**
     * Audio and stickers have no caption. Dropping the key and sending anyway
     * would put a second file in the chat, so this refuses before the request.
     */
    public function test_a_type_without_a_caption_is_refused_without_sending(): void
    {
        $this->fakeZApi();

        $audio = (new ZApiClient())->editCaption('UID', 'TOKEN', '5491100000000', 'MSG-1', 'https://cdn.example.com/nota.mp3', 'Nueva');
        $sticker = (new ZApiClient())->editCaption('UID', 'TOKEN', '5491100000000', 'MSG-1', 'https://cdn.example.com/s.webp', 'Nueva');

        $this->assertSame('Caption edit is not supported for send-audio', $audio['error'] ?? null);
        $this->assertSame('Caption edit is not supported for send-sticker', $sticker['error'] ?? null);
        Http::assertNothingSent();
    }

    public function test_an_unknown_extension_is_still_refused_without_sending(): void
    {
        $this->fakeZApi();

        $result = (new ZApiClient())->editCaption('UID', 'TOKEN', '5491100000000', 'MSG-1', 'https://cdn.example.com/x.exe', 'Nueva');

        $this->assertSame('Invalid file extension', $result['error'] ?? null);
        Http::assertNothingSent();
    }

    public function test_edit_caption_returns_the_send_resource_on_success(): void
    {
        $this->fakeZApi();

        $result = (new ZApiClient())->editCaption('UID', 'TOKEN', '5491100000000', 'MSG-1', 'https://cdn.example.com/foto.jpg', 'Nueva');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue($result['sent']);
        $this->assertSame('MSG-NEW', $result['id']);
    }

    public function test_zapi_lite_inherits_edit_caption_and_uses_its_own_config_prefix(): void
    {
        config([
            'zapi-lite.base_url' => 'https://lite.z-api.io',
            'zapi-lite.client_token' => 'lite-client-token',
        ]);

        Http::fake(['https://lite.z-api.io/*' => Http::response(['messageId' => 'MSG-NEW'], 200)]);

        (new ZApiLiteClient())->editCaption('UID', 'TOKEN', '5491100000000', 'MSG-1', 'https://cdn.example.com/foto.jpg', 'Nueva');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://lite.z-api.io/instances/UID/token/TOKEN/send-image'
                && $request->hasHeader('Client-Token', 'lite-client-token')
                && ($request->data()['editImageMessageId'] ?? null) === 'MSG-1';
        });
    }

    /** Plain media sends are unchanged: no edit key unless one was asked for. */
    public function test_send_file_without_the_option_sends_no_edit_key(): void
    {
        $this->fakeZApi();

        (new ZApiClient())->sendFile('UID', 'TOKEN', '5491100000000', 'https://cdn.example.com/foto.jpg', ['caption' => 'Hola']);

        Http::assertSent(function ($request) {
            foreach (['editMessageId', 'editImageMessageId', 'editVideoMessageId', 'editDocumentMessageId'] as $key) {
                if (array_key_exists($key, $request->data())) return false;
            }

            return ($request->data()['caption'] ?? null) === 'Hola';
        });
        Http::assertSentCount(1);
    }
}
