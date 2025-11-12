<?php

namespace Funnelchat\WapiGateway\Enums;

enum MessageTemplateTypeButtonEnum: string
{
    case URL = 'URL';
    case PHONE_NUMBER = 'PHONE_NUMBER';
    case QUICK_REPLY = 'QUICK_REPLY';
}
