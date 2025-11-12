<?php

namespace Funnelchat\WapiGateway\Providers;

use Illuminate\Support\ServiceProvider;
use Funnelchat\WapiGateway\Enums\ProviderEnum;
use Funnelchat\WapiGateway\Http\Controllers\UazapiInstanceController;
use Funnelchat\WapiGateway\Http\Controllers\ZApiInstanceController;
use Funnelchat\WapiGateway\Interfaces\WhatsAppProviderInstanceInterface;

class WhatsAppInstanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $resolver = function ($app) {
            $providerId = request()->header('provider') ?? ProviderEnum::ZApi->value;

            $controllers = [
                ProviderEnum::ZApi->value => ZApiInstanceController::class,
                ProviderEnum::Uazapi->value => UazapiInstanceController::class,
            ];

            $controller = $controllers[$providerId] ?? ZApiInstanceController::class;

            return $app->make($controller);
        };

        $this->app->singleton(WhatsAppProviderInstanceInterface::class, $resolver);
        if (class_exists('App\\Interfaces\\WhatsAppProviderInstanceInterface')) {
            $this->app->singleton(\App\Interfaces\WhatsAppProviderInstanceInterface::class, $resolver);
        }
    }

    public function boot(): void
    {
        // SDK puro: sin middlewares ni rutas
    }
}
