<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Traits;

use Funnelchat\WapiGateway\Traits\ResolvesMediaType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which type and extension sendFile() resolves for a URL, independently of
 * any provider. Every client feeds its own extension map into the trait; a
 * small one stands in for them here.
 */
class ResolvesMediaTypeTest extends TestCase
{
    private function resolve(string $fileUrl, array $options = []): array
    {
        $client = new class {
            use ResolvesMediaType;

            public function run(string $fileUrl, array $options): array
            {
                return $this->resolveMediaType($fileUrl, $options, fn (string $ext) => [
                    'jpg' => 'image', 'png' => 'image',
                    'mp4' => 'video',
                    'ogg' => 'audio',
                    'pdf' => 'document', 'docx' => 'document',
                    'webp' => 'sticker',
                ][$ext] ?? 'invalid');
            }
        };

        return $client->run($fileUrl, $options);
    }

    public static function guessProvider(): array
    {
        return [
            'plain URL' => ['https://cdn.example.com/a/photo.jpg', [], 'image', 'jpg'],
            'signed URL' => ['https://b.s3.amazonaws.com/a/photo.jpg?X-Amz-Expires=300&X-Amz-Signature=abc', [], 'image', 'jpg'],
            'fragment' => ['https://cdn.example.com/a/clip.mp4#t=10', [], 'video', 'mp4'],
            'query with dots' => ['https://cdn.example.com/a/photo.png?v=1.2.3', [], 'image', 'png'],
            'uppercase' => ['https://cdn.example.com/a/CONTRATO.PDF', [], 'document', 'pdf'],
            'dot in a directory only' => ['https://cdn.example.com/v1.2/doc.docx', [], 'document', 'docx'],
            // The whole-URL reading every client used before: kept when the
            // client knows it, so this URL builds the same request as before.
            'extension only in the query' => ['https://cdn.example.com/download?file=photo.jpg', [], 'image', 'jpg'],
            'script path, extension in the query' => ['https://cdn.example.com/get.php?file=doc.pdf', [], 'document', 'pdf'],
            'no extension, fileName' => ['https://cdn.example.com/media/8f3a2b', ['fileName' => 'Contrato.PDF'], 'document', 'pdf'],
            'unknown extension, fileName' => ['https://cdn.example.com/media/file.bin', ['fileName' => 'nota.ogg'], 'audio', 'ogg'],
            'path wins over fileName' => ['https://cdn.example.com/a/photo.jpg', ['fileName' => 'photo.pdf'], 'image', 'jpg'],
        ];
    }

    #[DataProvider('guessProvider')]
    public function test_guesses_the_type_from_the_path_extension(string $fileUrl, array $options, string $type, string $ext): void
    {
        $this->assertSame(['type' => $type, 'ext' => $ext], $this->resolve($fileUrl, $options));
    }

    public static function unresolvableProvider(): array
    {
        return [
            'no extension' => ['https://cdn.example.com/media/8f3a2b', []],
            'unknown extension' => ['https://cdn.example.com/media/file.bin', []],
            'host only' => ['https://cdn.example.com', []],
            'signed, no extension' => ['https://b.s3.amazonaws.com/media/8f3a2b?X-Amz-Signature=abc.def', []],
            'fileName without extension' => ['https://cdn.example.com/media/8f3a2b', ['fileName' => 'contrato']],
            'not a URL' => ['', []],
        ];
    }

    #[DataProvider('unresolvableProvider')]
    public function test_without_a_type_an_unknown_extension_is_the_existing_error(string $fileUrl, array $options): void
    {
        $this->assertSame(['error' => 'Invalid file extension'], $this->resolve($fileUrl, $options));
    }

    public static function requestedProvider(): array
    {
        return [
            'no extension' => ['https://cdn.example.com/media/8f3a2b', ['type' => 'image'], 'image', ''],
            'overrides the guess' => ['https://cdn.example.com/a/photo.jpg', ['type' => 'document'], 'document', 'jpg'],
            'unknown extension kept' => ['https://cdn.example.com/a/sheet.xlsx', ['type' => 'document'], 'document', 'xlsx'],
            'fileName lends the extension' => ['https://cdn.example.com/media/8f3a2b', ['type' => 'document', 'fileName' => 'contrato.pdf'], 'document', 'pdf'],
            'a candidate matching the type wins' => ['https://cdn.example.com/get.php?file=doc.pdf', ['type' => 'document'], 'document', 'pdf'],
            'case and spaces' => ['https://cdn.example.com/media/8f3a2b', ['type' => ' Video '], 'video', ''],
            'sticker' => ['https://cdn.example.com/media/8f3a2b', ['type' => 'sticker'], 'sticker', ''],
        ];
    }

    #[DataProvider('requestedProvider')]
    public function test_a_requested_type_is_used_as_is(string $fileUrl, array $options, string $type, string $ext): void
    {
        $this->assertSame(['type' => $type, 'ext' => $ext], $this->resolve($fileUrl, $options));
    }

    public static function blankTypeProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'spaces' => ['  '],
        ];
    }

    #[DataProvider('blankTypeProvider')]
    public function test_a_blank_type_counts_as_absent(?string $type): void
    {
        $this->assertSame(
            ['type' => 'image', 'ext' => 'jpg'],
            $this->resolve('https://cdn.example.com/a/photo.jpg', ['type' => $type]),
        );
    }

    public static function invalidTypeProvider(): array
    {
        return [
            'unknown word' => ['pdf'],
            'ptt' => ['ptt'],
            'not a string' => [['image']],
            'number' => [1],
        ];
    }

    #[DataProvider('invalidTypeProvider')]
    public function test_an_invalid_type_is_refused(mixed $type): void
    {
        $this->assertSame(
            ['error' => 'Invalid file type: expected one of image, video, audio, document, sticker'],
            $this->resolve('https://cdn.example.com/a/photo.jpg', ['type' => $type]),
        );
    }

    /** Only a plain token is an extension, so nothing odd reaches a provider path. */
    public function test_an_extension_that_is_not_a_plain_token_is_ignored(): void
    {
        $this->assertSame(
            ['type' => 'document', 'ext' => ''],
            $this->resolve('https://cdn.example.com/media/file.p%20df', ['type' => 'document']),
        );
    }
}
