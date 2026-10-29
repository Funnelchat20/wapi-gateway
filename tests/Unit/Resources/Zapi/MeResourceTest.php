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

    public function test_maps_lid_when_present(): void
    {
        $result = MeResource::make([
            'phone' => '5511999999999',
            'name' => 'Jane',
            'lid' => '176188567867647@lid',
        ]);

        $this->assertSame('176188567867647@lid', $result['lid']);
    }

    public function test_omits_lid_when_absent_or_empty(): void
    {
        foreach ([[], ['lid' => ''], ['lid' => null]] as $extra) {
            $result = MeResource::make(['phone' => '5511999999999', 'name' => 'Jane'] + $extra);

            $this->assertArrayNotHasKey('lid', $result);
        }
    }

    public function test_preserves_existing_fields_alongside_lid(): void
    {
        $result = MeResource::make([
            'phone' => '5511999999999',
            'name' => 'Jane',
            'imgUrl' => 'https://example.com/avatar.jpg',
            'isBusiness' => false,
            'about' => 'Hello there',
            'lid' => '176188567867647@lid',
        ]);

        $this->assertSame([
            'phone' => '5511999999999',
            'locale' => '',
            'name' => 'Jane',
            'avatar' => 'https://example.com/avatar.jpg',
            'isBusiness' => false,
            'about' => 'Hello there',
            'lid' => '176188567867647@lid',
        ], $result);
    }
}
