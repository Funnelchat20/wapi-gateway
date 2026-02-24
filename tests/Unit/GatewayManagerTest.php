<?php

namespace Funnelchat\WapiGateway\Tests\Unit;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Clients\UazapiClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Enums\ProviderEnum;
use Funnelchat\WapiGateway\Gateway\GatewayManager;
use Orchestra\Testbench\TestCase;

class GatewayManagerTest extends TestCase
{
    private GatewayManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new GatewayManager(
            new ZApiClient(),
            new UazapiClient(),
            new MetaClient(),
            new FunapiClient(),
        );
    }

    protected function getPackageProviders($app): array
    {
        return [\Funnelchat\WapiGateway\Providers\WapiServiceProvider::class];
    }

    // ──────────────────────────────────────────────────────────────
    // messages() — correct client per provider
    // ──────────────────────────────────────────────────────────────

    public function test_messages_returns_zapi_client_for_zapi_provider(): void
    {
        $this->assertInstanceOf(ZApiClient::class, $this->manager->messages(ProviderEnum::ZApi));
    }

    public function test_messages_returns_uazapi_client_for_uazapi_provider(): void
    {
        $this->assertInstanceOf(UazapiClient::class, $this->manager->messages(ProviderEnum::Uazapi));
    }

    public function test_messages_returns_meta_client_for_whatsapp_cloud_provider(): void
    {
        $this->assertInstanceOf(MetaClient::class, $this->manager->messages(ProviderEnum::WhatsAppCloud));
    }

    public function test_messages_returns_funapi_client_for_funapi_provider(): void
    {
        $this->assertInstanceOf(FunapiClient::class, $this->manager->messages(ProviderEnum::Funapi));
    }

    // ──────────────────────────────────────────────────────────────
    // instances()
    // ──────────────────────────────────────────────────────────────

    public function test_instances_returns_zapi_client(): void
    {
        $this->assertInstanceOf(ZApiClient::class, $this->manager->instances(ProviderEnum::ZApi));
    }

    public function test_instances_returns_uazapi_client(): void
    {
        $this->assertInstanceOf(UazapiClient::class, $this->manager->instances(ProviderEnum::Uazapi));
    }

    public function test_instances_returns_meta_client_for_cloud(): void
    {
        $this->assertInstanceOf(MetaClient::class, $this->manager->instances(ProviderEnum::WhatsAppCloud));
    }

    // ──────────────────────────────────────────────────────────────
    // templates() — always MetaClient
    // ──────────────────────────────────────────────────────────────

    public function test_templates_always_returns_meta_client(): void
    {
        $this->assertInstanceOf(MetaClient::class, $this->manager->templates());
    }

    // ──────────────────────────────────────────────────────────────
    // contacts()
    // ──────────────────────────────────────────────────────────────

    public function test_contacts_returns_zapi_client(): void
    {
        $this->assertInstanceOf(ZApiClient::class, $this->manager->contacts(ProviderEnum::ZApi));
    }

    public function test_contacts_returns_meta_client(): void
    {
        $this->assertInstanceOf(MetaClient::class, $this->manager->contacts(ProviderEnum::WhatsAppCloud));
    }

    // ──────────────────────────────────────────────────────────────
    // queue()
    // ──────────────────────────────────────────────────────────────

    public function test_queue_returns_zapi_client(): void
    {
        $this->assertInstanceOf(ZApiClient::class, $this->manager->queue(ProviderEnum::ZApi));
    }
}
