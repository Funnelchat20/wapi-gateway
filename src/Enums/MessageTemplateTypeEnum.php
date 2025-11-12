<?php

namespace Funnelchat\WapiGateway\Enums;

enum MessageTemplateTypeEnum: string
{
    case TEXT = 'text';
    case IMAGE = 'image';
    case DOCUMENT = 'document';
    case VIDEO = 'video';
    case LOCATION = 'location';
    case STICKER = 'sticker';
    case AUDIO = 'audio';
    case DEFAULT = 'default';
}
