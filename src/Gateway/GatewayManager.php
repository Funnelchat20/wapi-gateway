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

    public function groups(ProviderEnum $provider): object
    {
        return match ($provider) {
            ProviderEnum::ZApi => $this->zapi,
            ProviderEnum::Uazapi => $this->uazapi,
            ProviderEnum::WhatsAppCloud => $this->meta,
        };
    }

    public function contacts(ProviderEnum $provider): object
    {
        return match ($provider) {
            ProviderEnum::ZApi => $this->zapi,
            ProviderEnum::Uazapi => $this->uazapi,
            ProviderEnum::WhatsAppCloud => $this->meta,
        };
    }

    public function templates(): MetaClient
    {
        return $this->meta;
    }

    public function queue(ProviderEnum $provider): object
    {
        return match ($provider) {
            ProviderEnum::ZApi => $this->zapi,
            ProviderEnum::Uazapi => $this->uazapi,
            ProviderEnum::WhatsAppCloud => $this->meta,
        };
    }
}
