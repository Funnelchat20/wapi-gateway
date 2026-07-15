<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Resources\Zapi;

use Funnelchat\WapiGateway\Resources\Zapi\BusinessProfileResource;
use PHPUnit\Framework\TestCase;

class BusinessProfileResourceTest extends TestCase
{
    public function test_maps_full_business_profile_response(): void
    {
        $result = BusinessProfileResource::make([
            'description' => 'We sell shoes',
            'websites' => ['https://example.com', 'https://shop.example.com'],
            'email' => 'sales@example.com',
            'address' => '123 Main St',
            'categories' => ['Retail'],
            'businessHours' => ['mon' => '09:00-18:00'],
            'hasCoverPhoto' => true,
        ]);

        $this->assertSame([
            'description' => 'We sell shoes',
            'website' => ['https://example.com', 'https://shop.example.com'],
            'email' => 'sales@example.com',
            'address' => '123 Main St',
            'categories' => ['Retail'],
            'businessHours' => ['mon' => '09:00-18:00'],
            'hasCoverPhoto' => true,
        ], $result);
    }

    public function test_empty_input_returns_shape_with_defaults(): void
    {
        $result = BusinessProfileResource::make([]);

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

    public function test_partial_input_fills_missing_keys_with_defaults(): void
    {
        $result = BusinessProfileResource::make(['description' => 'Partial data only']);

        $this->assertSame('Partial data only', $result['description']);
        $this->assertSame([], $result['website']);
        $this->assertSame('', $result['email']);
        $this->assertFalse($result['hasCoverPhoto']);
    }

    public function test_normalizes_plural_websites_key_to_singular_website(): void
    {
        $result = BusinessProfileResource::make(['websites' => ['https://a.test']]);

        $this->assertArrayNotHasKey('websites', $result);
        $this->assertSame(['https://a.test'], $result['website']);
    }
}
