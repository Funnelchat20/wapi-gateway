<?php

namespace Funnelchat\WapiGateway\Traits;

/**
 * The `voiceNote` option of sendFile(): send an audio as a voice note (the
 * recorded-message bubble) instead of as an audio file. The same option name
 * and the same error codes as wapi's `send-file` (Funnelchat20/wapi#393), so
 * a caller can relay either one without translating.
 *
 * Absent or false leaves the provider payload byte-identical to what it was
 * before the option existed.
 */
trait HandlesVoiceNoteOption
{
    /**
     * A real boolean is taken as is. Anything else goes through
     * FILTER_VALIDATE_BOOLEAN, so a form value like "false" or "0" means no,
     * instead of being cast to true.
     */
    protected function wantsVoiceNote(array $options): bool
    {
        $value = $options['voiceNote'] ?? false;

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** The file cannot be sent as a voice note. Nothing was sent. */
    protected function invalidVoiceNoteFile(string $message): array
    {
        return ['error' => 'invalid_voice_note_file', 'message' => $message];
    }

    /** The provider cannot send a voice note at all. Nothing was sent. */
    protected function voiceNoteUnsupported(string $provider): array
    {
        return ['error' => 'unsupported', 'message' => $provider . ' does not support sending audio as a voice note.'];
    }
}
