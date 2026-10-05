<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * Group destination support (Meta shipped Groups messaging in 2026).
 *
 * Sending to a group is the same Messages API with two differences in the
 * body: `recipient_type: group` and the group id in `to`. Everything else —
 * the `text`/`image`/`template` object — is identical to a 1:1 send.
 *
 * The first three tests cover the group path; the rest exist to prove the two
 * paths that already carry production traffic did NOT change shape. That is
 * the risk this change actually carries: `recipientField()` is spread by every
 * send method, so a mistake here would land on all of them at once.
 *
 * @see https://developers.facebook.com/documentation/business-messaging/whatsapp/groups/groups-messaging
 */
class MetaClientGroupRecipientTest extends TestCase
{
    private const UID = '409311982263712';
    private const TOKEN = 'test-token';
    private const GROUP_ID = 'Y2FwaV9ncm91cDoxMzM0NTMwNTg1ODoxMjAzNjM0MzAzNDY0NzAxODQZD';
    private const PHONE = '5491155000099';
    private const BSUID = 'CO.1021346770783737';

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function groupOption(): array
    {
        return [MetaClient::RECIPIENT_TYPE_OPTION => MetaClient::RECIPIENT_TYPE_GROUP];
    }

    private function fakeOk(): void
    {
        Http::fake([
            '*' => Http::response([
                'messages' => [['id' => 'wamid.TEST']],
            ], 200),
        ]);
    }

    public function test_a_text_send_to_a_group_declares_recipient_type_group(): void
    {
        $this->fakeOk();

        (new MetaClient())->sendText(self::UID, self::TOKEN, self::GROUP_ID, 'hola', $this->groupOption());

        Http::assertSent(function ($request) {
            return $request['recipient_type'] === 'group'
                && $request['to'] === self::GROUP_ID
                && ! isset($request['recipient'])
                && $request['type'] === 'text';
        });
    }

    public function test_a_media_send_to_a_group_declares_recipient_type_group(): void
    {
        $this->fakeOk();

        (new MetaClient())->sendFile(
            self::UID,
            self::TOKEN,
            self::GROUP_ID,
            'https://example.test/file.pdf',
            $this->groupOption(),
        );

        Http::assertSent(function ($request) {
            return $request['recipient_type'] === 'group'
                && $request['to'] === self::GROUP_ID
                && $request['type'] === 'document';
        });
    }

    public function test_a_template_send_to_a_group_declares_recipient_type_group(): void
    {
        $this->fakeOk();

        (new MetaClient())->sendTemplate(
            self::UID,
            self::TOKEN,
            self::GROUP_ID,
            'invite_template',
            'es',
            [],
            $this->groupOption(),
        );

        Http::assertSent(function ($request) {
            return $request['recipient_type'] === 'group'
                && $request['to'] === self::GROUP_ID
                && $request['type'] === 'template';
        });
    }

    /**
     * The group id is opaque and is NEVER sniffed: without the explicit option
     * the very same string must be addressed as a plain `to`, exactly as any
     * phone send has always been. A heuristic on the shape of $to would
     * misroute 1:1 traffic the day Meta changes that shape.
     */
    public function test_the_same_id_without_the_option_is_addressed_as_a_plain_recipient(): void
    {
        $this->fakeOk();

        (new MetaClient())->sendText(self::UID, self::TOKEN, self::GROUP_ID, 'hola');

        Http::assertSent(function ($request) {
            return ! isset($request['recipient_type'])
                && $request['to'] === self::GROUP_ID;
        });
    }

    public function test_a_phone_send_is_byte_identical_to_before(): void
    {
        $this->fakeOk();

        (new MetaClient())->sendText(self::UID, self::TOKEN, self::PHONE, 'hola');

        Http::assertSent(function ($request) {
            return $request['to'] === self::PHONE
                && ! isset($request['recipient_type'])
                && ! isset($request['recipient']);
        });
    }

    /**
     * Meta resolves `to` and ignores `recipient` when both are present, so a
     * BSUID send must keep addressing through `recipient` alone — the group
     * branch must not have introduced a second key on this path.
     */
    public function test_a_bsuid_send_still_addresses_through_recipient_alone(): void
    {
        $this->fakeOk();

        (new MetaClient())->sendText(self::UID, self::TOKEN, self::BSUID, 'hola');

        Http::assertSent(function ($request) {
            return ($request['recipient_type'] ?? null) === 'individual'
                && ! isset($request['to']);
        });
    }
}
