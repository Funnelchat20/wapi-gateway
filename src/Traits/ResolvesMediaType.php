<?php

namespace Funnelchat\WapiGateway\Traits;

/**
 * Decides what kind of media sendFile() sends, and with which file extension,
 * for a URL the integrator hands over as is.
 *
 * The type comes from `$options['type']` when the caller gives one (image,
 * video, audio, document or sticker); the URL is then never used to guess it.
 * Without the option the type is guessed from the file extension, through the
 * client's own extension map.
 *
 * The extension is read from the URL path, so a query string or a fragment
 * never takes part: a signed URL like `.../photo.jpg?X-Amz-Signature=...`
 * resolves to `jpg`. The candidates, in order:
 *
 *   1. the extension of the URL path, lowercased;
 *   2. the extension of the whole URL, the way every client read it before
 *      this trait existed, and only when the client's map knows it. This keeps
 *      a URL that already worked, such as `.../download?file=a.jpg`, building
 *      the same request as before;
 *   3. the extension of `$options['fileName']`, for a URL with none.
 *
 * The first candidate the client's map agrees with wins: one that maps to the
 * requested type, or to any type when none was requested. A requested type
 * that no candidate agrees with keeps the first candidate, or '' when there is
 * none. Only plain alphanumeric extensions count, so whatever a client puts
 * in a provider path (Z-API's `send-document/{ext}`) is a single safe token.
 *
 * The URL itself is never rewritten: the provider receives it unchanged,
 * signature included.
 */
trait ResolvesMediaType
{
    /** The values `$options['type']` accepts. */
    protected const MEDIA_TYPES = ['image', 'video', 'audio', 'document', 'sticker'];

    /**
     * A blank `type` counts as absent. Any other value outside MEDIA_TYPES is
     * refused, case and surrounding spaces aside.
     *
     * @param  callable(string): string  $guess  The client's extension map: the
     *         type for an extension, or 'invalid' when it does not know it.
     * @return array{type: string, ext: string}|array{error: string} `ext` is ''
     *         only when the caller gave a type and no extension could be found.
     */
    protected function resolveMediaType(string $fileUrl, array $options, callable $guess): array
    {
        $requested = $options['type'] ?? null;
        if (is_string($requested)) {
            $requested = strtolower(trim($requested));
            if ($requested === '') $requested = null;
        }
        if ($requested !== null && ! in_array($requested, self::MEDIA_TYPES, true)) {
            return ['error' => 'Invalid file type: expected one of ' . implode(', ', self::MEDIA_TYPES)];
        }

        $candidates = $this->mediaExtensionCandidates($fileUrl, $options, $guess);
        foreach ($candidates as $ext) {
            $guessed = $guess($ext);
            if ($guessed !== 'invalid' && ($requested === null || $guessed === $requested)) {
                return ['type' => $requested ?? $guessed, 'ext' => $ext];
            }
        }

        if ($requested === null) return ['error' => 'Invalid file extension'];

        return ['type' => $requested, 'ext' => $candidates[0] ?? ''];
    }

    /** @return list<string> */
    private function mediaExtensionCandidates(string $fileUrl, array $options, callable $guess): array
    {
        $path = parse_url($fileUrl, PHP_URL_PATH);
        $wholeUrl = strtolower(pathinfo($fileUrl, PATHINFO_EXTENSION));
        $fileName = $options['fileName'] ?? null;

        $candidates = [
            self::plainExtension(is_string($path) ? $path : ''),
            $guess($wholeUrl) !== 'invalid' ? $wholeUrl : '',
            self::plainExtension(is_string($fileName) ? $fileName : ''),
        ];

        return array_values(array_unique(array_filter($candidates, fn (string $ext) => $ext !== '')));
    }

    /** The lowercased extension of $name, or '' when it has none or it is not a plain token. */
    private static function plainExtension(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]+$/', $ext) === 1 ? $ext : '';
    }
}
