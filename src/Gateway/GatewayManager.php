<?php

namespace Funnelchat\WapiGateway\Gateway;

use Funnelchat\WapiGateway\Enums\ProviderEnum;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\ZApiLiteClient;
use Funnelchat\WapiGateway\Clients\UazapiClient;
use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Clients\FunapiClient;

class GatewayManager
{
    public function __construct(
        private ZApiClient $zapi,
        private UazapiClient $uazapi,
        private MetaClient $meta,
        private FunapiClient $funapi,
        private ZApiLiteClient $zapiLite,
    ) {
    }

    public function messages(ProviderEnum $provider): object
    {
        return $this->resolveClient($provider);
    }

    public function instances(ProviderEnum $provider): object
    {
        return $this->resolveClient($provider);
    }

    public function groups(ProviderEnum $provider): object
    {
        return $this->resolveClient($provider);
    }

    public function contacts(ProviderEnum $provider): object
    {
        return $this->resolveClient($provider);
    }

    public function templates(): MetaClient
    {
        return $this->meta;
    }

    public function queue(ProviderEnum $provider): object
    {
        return $this->resolveClient($provider);
    }

    private function resolveClient(ProviderEnum $provider): object
    {
        return match ($provider) {
            ProviderEnum::ZApi => $this->zapi,
            ProviderEnum::WhatsAppCloud => $this->meta,
            ProviderEnum::FunApi => $this->funapi,
            ProviderEnum::ZApiLite => $this->zapiLite,
        };
    }
}
