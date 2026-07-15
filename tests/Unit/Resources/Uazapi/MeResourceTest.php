<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Resources\Uazapi;

use Funnelchat\WapiGateway\Resources\Uazapi\MeResource;
use PHPUnit\Framework\TestCase;

class MeResourceTest extends TestCase
{
    public function test_about_key_is_always_present_and_empty(): void
    {
        $result = MeResource::make([
            'profileName' => 'Jane',
            'profilePicUrl' => 'https://example.com/avatar.jpg',
            'isBusiness' => true,
        ]);

        $this->assertArrayHasKey('about', $result);
        $this->assertSame('', $result['about']);
    }

    public function test_about_stays_empty_even_if_an_unrelated_about_key_is_present_upstream(): void
    {
        // UAZAPI's /instance/status payload has no profile "about" concept —
        // this documents that the resource never leaks an unrelated key
        // through the `about` contract slot.
        $result = MeResource::make([
            'profileName' => 'Jane',
            'about' => 'should be ignored',
        ]);

        $this->assertSame('', $result['about']);
    }
}
