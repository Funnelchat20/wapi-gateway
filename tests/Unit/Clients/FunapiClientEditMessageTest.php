<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Editing on FunApi, built on the ASSUMPTION that whatsgo mirrors the Z-API
 * shape. Whatsgo's OpenAPI 3.112.5 documents none of these keys — see the
 * block comment above `FunapiClient::editMessage()` and
 * Funnelchat20/fun-api#211.
 *
 * What these tests pin is OUR side of the wire: that the key is built and sent
 * where this client overrides `sendText()`/`sendFile()` and would otherwise
 * drop it. They cannot prove whatsgo honours it.
 */
class FunapiClientEditMessageTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function fakeFunapi(): void
    {
        config([
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);

        Http::fake(['https://funapi.example.com/*' => Http::response(['zaapId' => 'ZAAP-1', 'messageId' => 'MSG-NEW'], 200)]);
    }

    /**
     * The regression guard. `FunapiClient` overrides `sendText()`, so the
     * inherited `editMessage()` used to go out as a plain send — a second
     * message in the group, reported as a success.
     */
    public function test_edit_message_carries_the_edit_key_this_override_used_to_drop(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->editMessage('UID', 'TOKEN', '120363000000000000-group', 'MSG-1', 'Texto corregido');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://funapi.example.com/instances/UID/token/TOKEN/send-text'
                && $request->hasHeader('Client-Token', 'funapi-client-token')
                && $request->data() === [
                    'phone' => '120363000000000000-group',
                    'message' => 'Texto corregido',
                    'editMessageId' => 'MSG-1',
                ];
        });
        Http::assertSentCount(1);
    }

    /** `messageId` is the quote option; an edit must never become a quote. */
    public function test_edit_message_does_not_quote_the_original(): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->editMessage('UID', 'TOKEN', '5491100000000', 'MSG-1', 'Texto corregido');

        Http::assertSent(fn ($request) => ! array_key_exists('messageId', $request->data()));
    }

    public static function mediaProvider(): array
    {
        return [
            // Funapi uses no send-document suffix, unlike Z-API.
            'image' => ['https://cdn.example.com/foto.jpg', 'send-image', 'editImageMessageId', 'image'],
            'video' => ['https://cdn.example.com/clip.mp4', 'send-video', 'editVideoMessageId', 'video'],
            'document' => ['https://cdn.example.com/precios.pdf', 'send-document', 'editDocumentMessageId', 'document'],
        ];
    }

    #[DataProvider('mediaProvider')]
    public function test_edit_caption_uses_the_key_that_matches_the_file_type(
        string $fileUrl,
        string $endpoint,
        string $editKey,
        string $mediaField,
    ): void {
        $this->fakeFunapi();

        (new FunapiClient())->editCaption('UID', 'TOKEN', '120363000000000000-group', 'MSG-1', $fileUrl, 'Precio corregido');

        Http::assertSent(function ($request) use ($endpoint, $editKey, $mediaField, $fileUrl) {
            $data = $request->data();

            return $request->url() === "https://funapi.example.com/instances/UID/token/TOKEN/$endpoint"
                && ($data[$editKey] ?? null) === 'MSG-1'
                && ($data[$mediaField] ?? null) === $fileUrl
                && ($data['caption'] ?? null) === 'Precio corregido';
        });
        Http::assertSentCount(1);
    }

    #[DataProvider('mediaProvider')]
    public function test_edit_caption_sends_no_other_edit_key(string $fileUrl, string $endpoint, string $editKey): void
    {
        $this->fakeFunapi();

        (new FunapiClient())->editCaption('UID', 'TOKEN', '5491100000000', 'MSG-1', $fileUrl, 'Nueva');

        $others = array_diff(['editMessageId', 'editImageMessageId', 'editVideoMessageId', 'editDocumentMessageId'], [$editKey]);

        Http::assertSent(function ($request) use ($others) {
            foreach ($others as $key) {
                if (array_key_exists($key, $request->data())) return false;
            }

            return true;
        });
    }

    /**
     * Audio and stickers have no caption. The refusal happens before the
     * request: dropping the key and sending would post a second file.
     */
    public function test_a_type_without_a_caption_is_refused_without_sending(): void
    {
        $this->fakeFunapi();

        $audio = (new FunapiClient())->editCaption('UID', 'TOKEN', '5491100000000', 'MSG-1', 'https://cdn.example.com/nota.mp3', 'Nueva');
        $sticker = (new FunapiClient())->editCaption('UID', 'TOKEN', '5491100000000', 'MSG-1', 'https://cdn.example.com/s.webp', 'Nueva');

        $this->assertSame('Caption edit is not supported for send-audio', $audio['error'] ?? null);
        $this->assertSame('Caption edit is not supported for send-sticker', $sticker['error'] ?? null);
        Http::assertNothingSent();
    }

    public function test_an_unknown_extension_is_still_refused_without_sending(): void
    {
        $this->fakeFunapi();

        $result = (new FunapiClient())->editCaption('UID', 'TOKEN', '5491100000000', 'MSG-1', 'https://cdn.example.com/x.exe', 'Nueva');

        $this->assertSame('Invalid file extension', $result['error'] ?? null);
        Http::assertNothingSent();
    }

    /** Plain sends stay unchanged: no edit key unless one was asked for. */
    public function test_plain_sends_carry_no_edit_key(): void
    {
        $this->fakeFunapi();

        $client = new FunapiClient();
        $client->sendText('UID', 'TOKEN', '5491100000000', 'Hola');
        $client->sendFile('UID', 'TOKEN', '5491100000000', 'https://cdn.example.com/foto.jpg', ['caption' => 'Hola']);

        Http::assertSent(function ($request) {
            foreach (['editMessageId', 'editImageMessageId', 'editVideoMessageId', 'editDocumentMessageId'] as $key) {
                if (array_key_exists($key, $request->data())) return false;
            }

            return true;
        });
        Http::assertSentCount(2);
    }
}
