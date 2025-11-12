<?php

namespace Funnelchat\WapiGateway\Providers;

use Illuminate\Support\ServiceProvider;
use Funnelchat\WapiGateway\Enums\ProviderEnum;
use Funnelchat\WapiGateway\Http\Controllers\UazapiController;
use Funnelchat\WapiGateway\Http\Controllers\WhatsAppCloudApiController;
use Funnelchat\WapiGateway\Http\Controllers\ZApiController;
use Funnelchat\WapiGateway\Interfaces\WhatsAppProviderControllerInterface;

class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $resolver = function ($app) {
            $providerId = request()->header('provider') ?? ProviderEnum::ZApi->value;

            $controllers = [
                ProviderEnum::ZApi->value => ZApiController::class,
                ProviderEnum::WhatsAppCloud->value => WhatsAppCloudApiController::class,
                ProviderEnum::Uazapi->value => UazapiController::class,
            ];

            $controller = $controllers[$providerId] ?? ZApiController::class;

            return $app->make($controller);
        };

        $this->app->singleton(WhatsAppProviderControllerInterface::class, $resolver);
        if (class_exists('App\\Interfaces\\WhatsAppProviderControllerInterface')) {
            $this->app->singleton(\App\Interfaces\WhatsAppProviderControllerInterface::class, $resolver);
        }
    }

    public function boot(): void
    {
    }
}
