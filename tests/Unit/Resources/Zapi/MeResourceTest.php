<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Resources\Zapi;

use Funnelchat\WapiGateway\Resources\Zapi\MeResource;
use PHPUnit\Framework\TestCase;

class MeResourceTest extends TestCase
{
    public function test_maps_about_when_present(): void
    {
        $result = MeResource::make([
            'phone' => '5511999999999',
            'name' => 'Jane',
            'imgUrl' => 'https://example.com/avatar.jpg',
            'isBusiness' => false,
            'about' => 'Disponible',
        ]);

        $this->assertSame('Disponible', $result['about']);
    }

    public function test_defaults_about_to_empty_string_when_absent(): void
    {
        $result = MeResource::make([
            'phone' => '5511999999999',
            'name' => 'Jane',
        ]);

        $this->assertArrayHasKey('about', $result);
        $this->assertSame('', $result['about']);
    }

    public function test_preserves_existing_fields_alongside_about(): void
    {
        $result = MeResource::make([
            'phone' => '5511999999999',
            'name' => 'Jane',
            'imgUrl' => 'https://example.com/avatar.jpg',
            'isBusiness' => true,
            'about' => 'Hello there',
        ]);

        $this->assertSame([
            'phone' => '5511999999999',
            'locale' => '',
            'name' => 'Jane',
            'avatar' => 'https://example.com/avatar.jpg',
            'isBusiness' => true,
            'about' => 'Hello there',
        ], $result);
    }
}
