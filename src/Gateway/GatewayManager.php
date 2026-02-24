<?php

namespace Funnelchat\WapiGateway\Gateway;

use Funnelchat\WapiGateway\Contracts\ContactsContract;
use Funnelchat\WapiGateway\Contracts\GroupsContract;
use Funnelchat\WapiGateway\Contracts\InstancesContract;
use Funnelchat\WapiGateway\Contracts\MessagesContract;
use Funnelchat\WapiGateway\Contracts\QueueContract;
use Funnelchat\WapiGateway\Enums\ProviderEnum;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\UazapiClient;
use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Exceptions\UnsupportedOperationException;

class GatewayManager
{
    public function __construct(
        private ZApiClient $zapi,
        private UazapiClient $uazapi,
        private MetaClient $meta,
        private FunapiClient $funapi,
    ) {
    }

    public function messages(ProviderEnum $provider): MessagesContract
    {
        return match ($provider) {
            ProviderEnum::ZApi => $this->zapi,
            ProviderEnum::Uazapi => $this->uazapi,
            ProviderEnum::WhatsAppCloud => $this->meta,
            ProviderEnum::Funapi => $this->funapi,
        };
    }

    public function instances(ProviderEnum $provider): InstancesContract
    {
        return match ($provider) {
            ProviderEnum::ZApi => $this->zapi,
            ProviderEnum::Uazapi => $this->uazapi,
            ProviderEnum::WhatsAppCloud => $this->meta,
            ProviderEnum::Funapi => $this->funapi,
        };
    }

    /**
     * @throws UnsupportedOperationException When the provider does not support group operations.
     */
    public function groups(ProviderEnum $provider): GroupsContract
    {
        return match ($provider) {
            ProviderEnum::ZApi => $this->zapi,
            ProviderEnum::Uazapi => $this->uazapi,
            ProviderEnum::WhatsAppCloud => throw new UnsupportedOperationException('groups', 'WhatsAppCloud'),
            ProviderEnum::Funapi => $this->funapi,
        };
    }

    public function contacts(ProviderEnum $provider): ContactsContract
    {
        return match ($provider) {
            ProviderEnum::ZApi => $this->zapi,
            ProviderEnum::Uazapi => $this->uazapi,
            ProviderEnum::WhatsAppCloud => $this->meta,
            ProviderEnum::Funapi => $this->funapi,
        };
    }

    public function templates(): MetaClient
    {
        return $this->meta;
    }

    /**
     * @throws UnsupportedOperationException When the provider does not support queue operations.
     */
    public function queue(ProviderEnum $provider): QueueContract
    {
        return match ($provider) {
            ProviderEnum::ZApi => $this->zapi,
            ProviderEnum::Uazapi => $this->uazapi,
            ProviderEnum::WhatsAppCloud => throw new UnsupportedOperationException('queue', 'WhatsAppCloud'),
            ProviderEnum::Funapi => $this->funapi,
        };
    }
}
