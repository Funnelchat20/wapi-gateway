<?php

namespace Funnelchat\WapiGateway\Gateway;

use Funnelchat\WapiGateway\Enums\ProviderEnum;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\UazapiClient;
use Funnelchat\WapiGateway\Clients\MetaClient;

class GatewayManager
{
    public function __construct(
        private ZApiClient $zapi,
        private UazapiClient $uazapi,
        private MetaClient $meta,
    ) {
    }

    public function messages(ProviderEnum $provider): object
    {
        return match ($provider) {
            ProviderEnum::ZApi => $this->zapi,
            ProviderEnum::Uazapi => $this->uazapi,
            ProviderEnum::WhatsAppCloud => $this->meta,
        };
    }

    public function instances(ProviderEnum $provider): object
    {
        return match ($provider) {
            ProviderEnum::ZApi => $this->zapi,
            ProviderEnum::Uazapi => $this->uazapi,
            ProviderEnum::WhatsAppCloud => $this->meta,
        };
    }
}

