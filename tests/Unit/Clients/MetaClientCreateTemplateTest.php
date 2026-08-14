<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class MetaClientCreateTemplateTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function fakeGraph(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'id' => 'TEMPLATE_ID',
                'status' => 'PENDING',
                'category' => 'MARKETING',
            ], 200),
        ]);
    }

    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'promo_verano',
            'language_code' => 'es',
            'category' => 'MARKETING',
            'type' => 'text',
            'body' => 'Hola {{1}}',
        ], $overrides);
    }

    /**
     * The whole point of the change: when the caller opts in, Meta must be told
     * it may reassign the category instead of rejecting the template.
     */
    public function test_forwards_allow_category_change_when_present(): void
    {
        $this->fakeGraph();

        (new MetaClient())->createTemplate('WABA', 'TOKEN', $this->baseData([
            'allow_category_change' => true,
        ]));

        Http::assertSent(fn($request) => $request->method() === 'POST'
            && ($request->data()['allow_category_change'] ?? null) === true);
    }

    /** Backwards compatibility: absent key ⇒ the payload never carries it. */
    public function test_omits_allow_category_change_when_absent(): void
    {
        $this->fakeGraph();

        (new MetaClient())->createTemplate('WABA', 'TOKEN', $this->baseData());

        Http::assertSent(fn($request) => $request->method() === 'POST'
            && ! array_key_exists('allow_category_change', $request->data()));
    }
}
