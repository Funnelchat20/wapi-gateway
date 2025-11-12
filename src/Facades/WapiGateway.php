<?php

namespace Funnelchat\WapiGateway\Facades;

use Illuminate\Support\Facades\Facade;

class WapiGateway extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'wapi.gateway';
    }
}

