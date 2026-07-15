<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\MetaClient;
use PHPUnit\Framework\TestCase;

class MetaClientBusinessProfileTest extends TestCase
{
    public function test_business_profile_is_not_applicable_and_returns_empty_defaults(): void
    {
        $client = new MetaClient();

        $result = $client->businessProfile('WABA_ID', 'TOKEN');

        $this->assertSame([
            'description' => '',
            'website' => [],
            'email' => '',
            'address' => '',
            'categories' => [],
            'businessHours' => [],
            'hasCoverPhoto' => false,
        ], $result);
    }
}
