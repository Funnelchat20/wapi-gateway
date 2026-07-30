<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Meta started rolling out Business-Scoped User IDs on 2026-07-29: contacts that
 * reach the business through a username without a recent interaction arrive with
 * no phone number at all. Answering them requires addressing the payload through
 * `recipient` instead of `to` — and never both, since Meta resolves the phone
 * and drops the BSUID when the two are present.
 */
class MetaClientBsuidTest extends TestCase
{
    private const BSUID = 'CO.1021346770783737';
    private const ENTERPRISE_BSUID = 'US.ENT.11815799212886844830';
    private const PHONE = '5491123456789';

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

    /** Ignores the typing-indicator call, which carries no destination field. */
    private function assertAddressedBy(string $field, string $value): void
    {
        $other = $field === 'to' ? 'recipient' : 'to';
        Http::assertSent(function ($request) use ($field, $other, $value) {
            $data = $request->data();
            if (!isset($data['type'])) {
                return false; // the mark-as-read/typing call, not a send
            }

            return ($data[$field] ?? null) === $value
                && !array_key_exists($other, $data);
        });
    }

    /**
     * Every send method that builds a payload, exercised through its real
     * signature. sendPoll/sendPtv are intentionally absent: they are
     * 'Not supported' stubs on this provider and never build a payload.
     *
     * @return array<string, array{0: callable}>
     */
    public static function sendMethodProvider(): array
    {
        return [
            'sendText' => [fn(MetaClient $c, string $to) => $c->sendText('WABA', 'TOKEN', $to, 'Hola')],
            'sendFile' => [fn(MetaClient $c, string $to) => $c->sendFile('WABA', 'TOKEN', $to, 'https://cdn.example.com/catalogo.pdf')],
            'sendLocation' => [fn(MetaClient $c, string $to) => $c->sendLocation('WABA', 'TOKEN', $to, -34.6037, -58.3816)],
            'sendButtons' => [fn(MetaClient $c, string $to) => $c->sendButtons('WABA', 'TOKEN', $to, '¿Seguimos?', [['id' => 'yes', 'label' => 'Sí']])],
            'sendButtonLink' => [fn(MetaClient $c, string $to) => $c->sendButtonLink('WABA', 'TOKEN', $to, 'Mirá', 'https://tienda.example.com', 'Ver')],
            'sendOptionList' => [fn(MetaClient $c, string $to) => $c->sendOptionList('WABA', 'TOKEN', $to, 'Elegí', 'Opciones', [['id' => '1', 'title' => 'Uno']])],
            'sendLink' => [fn(MetaClient $c, string $to) => $c->sendLink('WABA', 'TOKEN', $to, 'Mirá esto', 'https://tienda.example.com/promo')],
            'sendTemplate' => [fn(MetaClient $c, string $to) => $c->sendTemplate('WABA', 'TOKEN', $to, 'bienvenida', 'es', [])],
            'sendContact' => [fn(MetaClient $c, string $to) => $c->sendContact('WABA', 'TOKEN', $to, 'Soporte', '5491100000000')],
        ];
    }

    #[DataProvider('sendMethodProvider')]
    public function test_a_bsuid_destination_is_addressed_through_recipient(callable $send): void
    {
        $this->fakeGraph();

        $send(new MetaClient(), self::BSUID);

        $this->assertAddressedBy('recipient', self::BSUID);
    }

    #[DataProvider('sendMethodProvider')]
    public function test_an_enterprise_bsuid_destination_is_addressed_through_recipient(callable $send): void
    {
        $this->fakeGraph();

        $send(new MetaClient(), self::ENTERPRISE_BSUID);

        $this->assertAddressedBy('recipient', self::ENTERPRISE_BSUID);
    }

    /**
     * The regression that matters: ~97% of traffic is a plain phone, and it must
     * keep travelling in `to` exactly as before.
     */
    #[DataProvider('sendMethodProvider')]
    public function test_a_phone_destination_still_travels_in_to(callable $send): void
    {
        $this->fakeGraph();

        $send(new MetaClient(), self::PHONE);

        $this->assertAddressedBy('to', self::PHONE);
    }

    /** The rest of the payload must be untouched by the destination switch. */
    public function test_the_bsuid_switch_changes_nothing_else_in_the_payload(): void
    {
        $this->fakeGraph();

        (new MetaClient())->sendLink('WABA', 'TOKEN', self::BSUID, 'Mirá esto', 'https://tienda.example.com/promo');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return ($data['messaging_product'] ?? null) === 'whatsapp'
                && ($data['type'] ?? null) === 'text'
                && ($data['text']['preview_url'] ?? null) === true
                && ($data['text']['body'] ?? null) === 'Mirá esto https://tienda.example.com/promo';
        });
    }

    public function test_a_padded_bsuid_is_sent_trimmed(): void
    {
        $this->fakeGraph();

        (new MetaClient())->sendText('WABA', 'TOKEN', '  ' . self::BSUID . '  ', 'Hola');

        $this->assertAddressedBy('recipient', self::BSUID);
    }

    /** The indicator rides on a wamid and has no destination field at all. */
    public function test_the_typing_indicator_is_unaffected_by_a_bsuid_destination(): void
    {
        $this->fakeGraph();

        (new MetaClient())->sendText('WABA', 'TOKEN', self::BSUID, 'Hola', [
            'typing' => ['lastInboundId' => 'wamid.IN'],
        ]);

        Http::assertSent(fn($request) => ($request->data()['status'] ?? null) === 'read'
            && ($request->data()['message_id'] ?? null) === 'wamid.IN'
            && !array_key_exists('to', $request->data())
            && !array_key_exists('recipient', $request->data()));
        Http::assertSentCount(2);
    }

    /**
     * 131062 = message type not supported for this recipient. Callers must be
     * able to tell it apart from a generic failure, because retrying the same
     * payload can never succeed — the message has to go out as another type.
     */
    public function test_error_131062_is_flagged_as_unsupported_for_bsuid(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Message type is not currently supported for this recipient',
                    'type' => 'OAuthException',
                    'code' => 131062,
                    'fbtrace_id' => 'Axxxxxxxxxx',
                ],
            ], 400),
        ]);

        $result = (new MetaClient())->sendLocation('WABA', 'TOKEN', self::BSUID, -34.6037, -58.3816);

        $this->assertSame(131062, $result['error_code']);
        $this->assertTrue($result['unsupported_for_bsuid']);
        // The raw Meta error is preserved verbatim alongside the flag.
        $this->assertSame(131062, $result['error']['code']);
        $this->assertSame('Message type is not currently supported for this recipient', $result['error']['message']);
    }

    /** Same flag when Meta reports the detail under error_subcode. */
    public function test_error_131062_is_flagged_when_reported_as_a_subcode(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Unsupported', 'code' => 100, 'error_subcode' => 131062],
            ], 400),
        ]);

        $result = (new MetaClient())->sendText('WABA', 'TOKEN', self::BSUID, 'Hola');

        $this->assertTrue($result['unsupported_for_bsuid']);
    }

    /**
     * The flag describes the destination we actually addressed, not just the
     * number in the body. `error_subcode` is a different numbering space than
     * `code`, so a phone send coming back with subcode 131062 must stay a plain
     * failure — otherwise the caller re-sends as "another type" instead of
     * surfacing the real error.
     */
    public function test_a_phone_send_is_never_flagged_as_unsupported_for_bsuid(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Unsupported', 'code' => 100, 'error_subcode' => 131062],
            ], 400),
        ]);

        $result = (new MetaClient())->sendText('WABA', 'TOKEN', self::PHONE, 'Hola');

        $this->assertArrayNotHasKey('unsupported_for_bsuid', $result);
        $this->assertArrayNotHasKey('error_code', $result);
    }

    /** Any other failure keeps the shape callers already handle. */
    public function test_other_send_errors_are_not_flagged(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid parameter', 'code' => 100],
            ], 400),
        ]);

        $result = (new MetaClient())->sendText('WABA', 'TOKEN', self::PHONE, 'Hola');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('error_code', $result);
        $this->assertArrayNotHasKey('unsupported_for_bsuid', $result);
        $this->assertSame('Invalid parameter', $result['error']['message']);
    }

    /** A body with no `error` object at all must not blow up the flag check. */
    public function test_a_failure_without_an_error_object_falls_back_cleanly(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response('', 500),
        ]);

        $result = (new MetaClient())->sendText('WABA', 'TOKEN', self::PHONE, 'Hola');

        $this->assertSame('Failed to send', $result['error']);
        $this->assertArrayNotHasKey('unsupported_for_bsuid', $result);
    }
}
