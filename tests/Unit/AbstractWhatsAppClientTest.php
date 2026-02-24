<?php

namespace Funnelchat\WapiGateway\Tests\Unit;

use Funnelchat\WapiGateway\Clients\AbstractWhatsAppClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Orchestra\Testbench\TestCase;

class AbstractWhatsAppClientTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    public function test_build_url_uses_template_when_available(): void
    {
        config(['stub.url_template' => 'https://stub.test/instances/UID/token/TOKEN/ACTION']);

        $client = new class extends AbstractWhatsAppClient {
            protected function getConfigPrefix(): string
            {
                return 'stub';
            }

            public function build(string $uid, string $token, string $action): string
            {
                return $this->buildUrl($uid, $token, $action);
            }
        };

        $this->assertSame(
            'https://stub.test/instances/abc/token/xyz/send-text',
            $client->build('abc', 'xyz', 'send-text')
        );
    }

    public function test_build_url_falls_back_to_base_path(): void
    {
        config([
            'stub.url_template' => null,
            'stub.base_url' => 'https://stub-base.test/',
        ]);

        $client = new class extends AbstractWhatsAppClient {
            protected function getConfigPrefix(): string
            {
                return 'stub';
            }

            public function build(string $uid, string $token, string $action): string
            {
                return $this->buildUrl($uid, $token, $action);
            }
        };

        $this->assertSame(
            'https://stub-base.test/instances/abc/token/xyz/send-text',
            $client->build('abc', 'xyz', 'send-text')
        );
    }
}
