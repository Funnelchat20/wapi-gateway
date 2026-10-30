<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * whatsgo documents update-newsletter-{name,description,picture} as POST with an
 * `@newsletter` id and `pictureUrl` (OpenAPI v3.114.0). A PUT is answered 405.
 */
class FunapiClientNewsletterUpdateTest extends TestCase
{
    private const BASE = 'https://funapi.example.com/instances/UID/token/TOKEN/';

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'funapi.base_url' => 'https://funapi.example.com',
            'funapi.client_token' => 'funapi-client-token',
        ]);
    }

    public function test_update_newsletter_name_posts_the_documented_body(): void
    {
        Http::fake([self::BASE . 'update-newsletter-name' => Http::response(['value' => true], 201)]);

        $result = (new FunapiClient())->updateNewsletterName('UID', 'TOKEN', '120363431787161907', 'Nuevo nombre');

        $this->assertSame(['value' => true], $result);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === self::BASE . 'update-newsletter-name'
            && $request->hasHeader('Client-Token', 'funapi-client-token')
            && $request->data() === ['id' => '120363431787161907@newsletter', 'name' => 'Nuevo nombre']);
    }

    public function test_update_newsletter_description_posts_the_documented_body(): void
    {
        Http::fake([self::BASE . 'update-newsletter-description' => Http::response(['value' => true], 201)]);

        (new FunapiClient())->updateNewsletterDescription('UID', 'TOKEN', '120363431787161907', 'Nueva descripción');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === self::BASE . 'update-newsletter-description'
            && $request->data() === ['id' => '120363431787161907@newsletter', 'description' => 'Nueva descripción']);
    }

    public function test_update_newsletter_picture_sends_picture_url_not_picture(): void
    {
        Http::fake([self::BASE . 'update-newsletter-picture' => Http::response(['value' => true], 201)]);

        (new FunapiClient())->updateNewsletterPicture('UID', 'TOKEN', '120363431787161907', 'https://cdn.example.com/p.jpg');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === self::BASE . 'update-newsletter-picture'
            && $request->data() === ['id' => '120363431787161907@newsletter', 'pictureUrl' => 'https://cdn.example.com/p.jpg']);
    }

    public function test_an_id_that_already_has_the_suffix_is_not_suffixed_twice(): void
    {
        Http::fake([self::BASE . 'update-newsletter-name' => Http::response(['value' => true], 201)]);

        (new FunapiClient())->updateNewsletterName('UID', 'TOKEN', '120363431787161907@newsletter', 'Nombre');

        Http::assertSent(fn ($request) => $request->data()['id'] === '120363431787161907@newsletter');
    }

    public function test_an_upstream_error_is_returned_as_an_error(): void
    {
        Http::fake([self::BASE . 'update-newsletter-picture' => Http::response(['error' => 'internal error'], 500)]);

        $result = (new FunapiClient())->updateNewsletterPicture('UID', 'TOKEN', '120363431787161907', 'https://cdn.example.com/p.jpg');

        $this->assertArrayHasKey('error', $result);
    }
}
