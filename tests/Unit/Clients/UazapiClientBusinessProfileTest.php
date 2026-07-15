<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\UazapiClient;
use PHPUnit\Framework\TestCase;

class UazapiClientBusinessProfileTest extends TestCase
{
    public function test_business_profile_is_not_supported_and_returns_empty_defaults(): void
    {
        $client = new UazapiClient();

        $result = $client->businessProfile('UID', 'TOKEN');

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
