<?php

namespace Funnelchat\WapiGateway\Enums;

enum FileExtensionEnum: string
{
    case jpg = 'jpg';
    case jpeg = 'jpeg';
    case png = 'png';
    case gif = 'gif';
    case pdf = 'pdf';
    case doc = 'doc';
    case docx = 'docx';
    case aac = 'aac';
    case oga = 'oga';
    case ogg = 'ogg';
    case mp3 = 'mp3';
    case mp4 = 'mp4';
    case m4a = 'm4a';
    case opus = 'opus';
    case txt = 'txt';
    case mov = 'mov';
    case svg = 'svg';
    case webp = 'webp';

    public function type(): string
    {
        return match ($this) {
            self::jpg, self::jpeg, self::png => 'image',
            self::pdf, self::doc, self::docx => 'document',
            self::aac, self::oga, self::ogg, self::mp3, self::m4a, self::opus => 'audio',
            self::gif, self::mp4, self::mov => 'video',
            self::svg, self::webp => 'sticker',
            default => 'Invalid',
        };
    }

    public function isAudio(): bool
    {
        return in_array($this, [self::aac, self::oga, self::ogg, self::mp3, self::m4a, self::opus]);
    }

    public function isSticker(): bool
    {
        return in_array($this, [self::svg, self::webp]);
    }

    public function isDocument(): bool
    {
        return in_array($this, [self::pdf, self::doc, self::docx]);
    }

    public function canHaveCaption(): bool
    {
        return in_array($this, [self::jpg, self::jpeg, self::png, self::gif, self::pdf, self::doc, self::docx, self::mp4, self::mov]);
    }
}
