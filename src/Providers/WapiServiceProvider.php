<?php

namespace Funnelchat\WapiGateway\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Resources\Json\JsonResource;
use Funnelchat\WapiGateway\Interfaces\ServiceInterface;
use Funnelchat\WapiGateway\Interfaces\RequestInterface;
use Funnelchat\WapiGateway\Gateway\GatewayManager;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\UazapiClient;
use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Clients\FunapiClient;

class WapiServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/wapi.php', 'wapi');

        $this->app->singleton(ServiceInterface::class, function ($app) {
            return new class implements ServiceInterface {};
        });

        $this->app->singleton(RequestInterface::class, function ($app) {
            return new class implements RequestInterface {};
        });
    }

    public function boot()
    {
        JsonResource::withoutWrapping();
        $this->publishes([
            __DIR__ . '/../../config/zapi.php' => config_path('zapi.php'),
            __DIR__ . '/../../config/uazapi.php' => config_path('uazapi.php'),
            __DIR__ . '/../../config/funapi.php' => config_path('funapi.php'),
            __DIR__ . '/../../config/constants.php' => config_path('constants.php'),
            __DIR__ . '/../../config/wapi.php' => config_path('wapi.php'),
        ], 'wapi-config');

        $this->app->singleton('wapi.gateway', function ($app) {
            return new GatewayManager(new ZApiClient(), new UazapiClient(), new MetaClient(), new FunapiClient());
        });
    }
}
