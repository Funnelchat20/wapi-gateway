<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class MetaClientSendContactTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function fakeGraph(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.OUT']],
            ], 200),
        ]);
    }

    /**
     * Funnelchat20/conversations#1771: `phone` bare digits with no leading `+`
     * reads as an incomplete local number to the recipient's OS, which
     * re-prepends the country code it detects from context when the vCard is
     * saved (e.g. "573108261101" -> "+57 573108261101"). A leading `+` marks
     * the number as already-international, so nothing downstream guesses.
     */
    public function test_send_contact_prefixes_the_phone_field_with_a_plus(): void
    {
        $this->fakeGraph();

        (new MetaClient())->sendContact('WABA', 'TOKEN', '5491100000000', 'Juan', '573108261101');

        Http::assertSent(function ($request) {
            $phones = $request->data()['contacts'][0]['phones'][0] ?? [];

            return ($phones['phone'] ?? null) === '+573108261101';
        });
    }

    /** wa_id must stay bare digits per Meta's contract — never a `+`. */
    public function test_send_contact_keeps_wa_id_as_bare_digits(): void
    {
        $this->fakeGraph();

        (new MetaClient())->sendContact('WABA', 'TOKEN', '5491100000000', 'Juan', '573108261101');

        Http::assertSent(function ($request) {
            $phones = $request->data()['contacts'][0]['phones'][0] ?? [];

            return ($phones['wa_id'] ?? null) === '573108261101';
        });
    }

    /** A stray `+` or formatting on the way in must not leak into wa_id or double up in phone. */
    public function test_send_contact_normalizes_an_already_formatted_input(): void
    {
        $this->fakeGraph();

        (new MetaClient())->sendContact('WABA', 'TOKEN', '5491100000000', 'Juan', '+57 310 826 1101');

        Http::assertSent(function ($request) {
            $phones = $request->data()['contacts'][0]['phones'][0] ?? [];

            return ($phones['phone'] ?? null) === '+573108261101'
                && ($phones['wa_id'] ?? null) === '573108261101';
        });
    }
}
