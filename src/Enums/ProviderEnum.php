<?php

namespace Funnelchat\WapiGateway\Enums;

enum ProviderEnum: int
{
    case ZApi = 1;
    case WhatsAppCloud = 2;
    case FunApi = 3;
    case ZApiLite = 4;
}
