<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CloudApiGroupsContract on MetaClient — WhatsApp Cloud API's native Groups
 * feature (OBA only). Separate from the legacy GroupsContract exercised by
 * ZApiClient/UazapiClient/FunapiClient tests: this contract only exists for
 * Meta, so these tests live on their own rather than as a shared data
 * provider across clients.
 */
class MetaClientCloudApiGroupsTest extends TestCase
{
    private const BSUID = 'CO.1021346770783737';
    private const PHONE = '5491123456789';
    private const WABA = 'WABA_PHONE_NUMBER_ID';
    private const GROUP_ID = 'GROUP_ID_123';
    private const JOIN_REQUEST_ID = 'MTY0NjcwNDM1OTU6MTIwMzYzNDA0Njk0MjMzODIw';

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function fakeGraph(array $body = ['id' => self::GROUP_ID], int $status = 200): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response($body, $status),
        ]);
    }

    /** Field names confirmed against a real Cloud API Groups call (staging, 2026-09-08) — see MetaClient::createGroup(). */
    public function test_creates_a_group_with_just_a_name(): void
    {
        $this->fakeGraph();

        (new MetaClient())->createGroup(self::WABA, 'TOKEN', 'Clientes VIP CDMX');

        Http::assertSent(fn($request) => $request->method() === 'POST'
            && str_contains($request->url(), self::WABA . '/groups')
            && $request->data() === ['messaging_product' => 'whatsapp', 'subject' => 'Clientes VIP CDMX']);
    }

    /** $options is a pass-through bag, not named params — Meta's accepted creation fields beyond `subject` are unconfirmed. */
    public function test_creates_a_group_forwards_extra_options_alongside_name(): void
    {
        $this->fakeGraph();

        (new MetaClient())->createGroup(self::WABA, 'TOKEN', 'Clientes VIP CDMX', ['description' => 'Soporte prioritario']);

        Http::assertSent(fn($request) => $request->data() === [
            'messaging_product' => 'whatsapp',
            'subject' => 'Clientes VIP CDMX',
            'description' => 'Soporte prioritario',
        ]);
    }

    public function test_create_group_failure_returns_an_error_array(): void
    {
        $this->fakeGraph(['error' => ['message' => 'Invalid parameter']], 400);

        $result = (new MetaClient())->createGroup(self::WABA, 'TOKEN', 'x');

        $this->assertSame(['message' => 'Invalid parameter'], $result['error']);
    }

    public function test_deletes_a_group(): void
    {
        $this->fakeGraph(['success' => true]);

        (new MetaClient())->deleteGroup(self::WABA, 'TOKEN', self::GROUP_ID);

        Http::assertSent(fn($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), self::GROUP_ID));
    }

    public function test_gets_a_group_without_fields(): void
    {
        $this->fakeGraph(['id' => self::GROUP_ID, 'name' => 'Clientes VIP CDMX']);

        (new MetaClient())->group(self::WABA, 'TOKEN', self::GROUP_ID);

        Http::assertSent(fn($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), self::GROUP_ID)
            && !str_contains($request->url(), '?'));
    }

    public function test_gets_a_group_with_specific_fields(): void
    {
        $this->fakeGraph();

        (new MetaClient())->group(self::WABA, 'TOKEN', self::GROUP_ID, ['name', 'participants']);

        Http::assertSent(fn($request) => str_contains($request->url(), 'fields=name%2Cparticipants'));
    }

    public function test_lists_groups_for_the_business_number(): void
    {
        $this->fakeGraph(['data' => []]);

        (new MetaClient())->groups(self::WABA, 'TOKEN');

        Http::assertSent(fn($request) => $request->method() === 'GET'
            && str_contains($request->url(), self::WABA . '/groups')
            && !str_contains($request->url(), '?'));
    }

    public function test_lists_groups_forwards_pagination_options(): void
    {
        $this->fakeGraph(['data' => []]);

        (new MetaClient())->groups(self::WABA, 'TOKEN', ['limit' => 25, 'after' => 'CURSOR']);

        Http::assertSent(fn($request) => str_contains($request->url(), 'limit=25')
            && str_contains($request->url(), 'after=CURSOR'));
    }

    public function test_updates_group_settings_with_the_given_body(): void
    {
        $this->fakeGraph(['success' => true]);

        (new MetaClient())->updateGroupSettings(self::WABA, 'TOKEN', self::GROUP_ID, ['description' => 'Nueva descripción']);

        Http::assertSent(fn($request) => $request->method() === 'POST'
            && str_ends_with(explode('?', $request->url())[0], self::GROUP_ID)
            && $request->data() === ['description' => 'Nueva descripción']);
    }

    public function test_gets_the_group_invite_link(): void
    {
        $this->fakeGraph(['invite_link' => 'https://chat.whatsapp.com/XYZ']);

        $result = (new MetaClient())->getGroupInviteLink(self::WABA, 'TOKEN', self::GROUP_ID);

        Http::assertSent(fn($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), self::GROUP_ID . '/invite_link'));
        $this->assertSame('https://chat.whatsapp.com/XYZ', $result['invite_link']);
    }

    public function test_resets_the_group_invite_link(): void
    {
        $this->fakeGraph(['invite_link' => 'https://chat.whatsapp.com/NEW']);

        (new MetaClient())->resetGroupInviteLink(self::WABA, 'TOKEN', self::GROUP_ID);

        Http::assertSent(fn($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), self::GROUP_ID . '/invite_link'));
    }

    /** The whole point of sendGroupInviteTemplate(): the group_id parameter, not a raw link. */
    public function test_send_group_invite_template_builds_a_group_id_body_parameter(): void
    {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        (new MetaClient())->sendGroupInviteTemplate(self::WABA, 'TOKEN', self::PHONE, 'group_invite_link', 'es', self::GROUP_ID);

        Http::assertSent(fn($request) => ($request->data()['type'] ?? null) === 'template'
            && $request->data()['template']['name'] === 'group_invite_link'
            && $request->data()['template']['language']['code'] === 'es'
            && $request->data()['template']['components'][0] === [
                'type' => 'body',
                'parameters' => [['type' => 'group_id', 'group_id' => self::GROUP_ID]],
            ]);
    }

    /**
     * Delegating to sendTemplate() must not be a coincidence that happens to
     * pass today — a BSUID recipient has to keep routing through `recipient`
     * exactly as any other template send does.
     */
    public function test_send_group_invite_template_routes_a_bsuid_recipient_through_recipient(): void
    {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        (new MetaClient())->sendGroupInviteTemplate(self::WABA, 'TOKEN', self::BSUID, 'group_invite_link', 'es', self::GROUP_ID);

        Http::assertSent(fn($request) => ($request->data()['recipient'] ?? null) === self::BSUID
            && !array_key_exists('to', $request->data()));
    }

    public function test_removes_a_participant_identified_by_phone(): void
    {
        $this->fakeGraph(['success' => true]);

        (new MetaClient())->removeGroupParticipant(self::WABA, 'TOKEN', self::GROUP_ID, self::PHONE);

        Http::assertSent(fn($request) => $request->method() === 'DELETE'
            && str_ends_with(explode('?', $request->url())[0], self::GROUP_ID . '/participants')
            && $request->data() === ['participants' => [['user' => self::PHONE]]]);
    }

    /** The BSUID branch of participantField() — mirrors recipientField()'s user/user_id split. */
    public function test_removes_a_participant_identified_by_bsuid(): void
    {
        $this->fakeGraph(['success' => true]);

        (new MetaClient())->removeGroupParticipant(self::WABA, 'TOKEN', self::GROUP_ID, self::BSUID);

        Http::assertSent(fn($request) => $request->data() === ['participants' => [['user_id' => self::BSUID]]]);
    }

    public function test_gets_pending_join_requests(): void
    {
        $this->fakeGraph(['data' => []]);

        (new MetaClient())->getGroupJoinRequests(self::WABA, 'TOKEN', self::GROUP_ID);

        Http::assertSent(fn($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), self::GROUP_ID . '/join_requests'));
    }

    /**
     * Meta's reference (read 2026-10-16) identifies a join request by its own
     * id, not by the participant: `{"messaging_product":"whatsapp",
     * "join_requests":["<JOIN_REQUEST_ID>"]}`. A single string id is exactly
     * how communities' adapter calls it.
     */
    public function test_approves_a_join_request_by_its_id(): void
    {
        $answer = ['messaging_product' => 'whatsapp', 'approved_join_requests' => [self::JOIN_REQUEST_ID]];
        $this->fakeGraph($answer);

        $result = (new MetaClient())->approveGroupJoinRequest(self::WABA, 'TOKEN', self::GROUP_ID, self::JOIN_REQUEST_ID);

        $this->assertSame($answer, $result);
        Http::assertSentCount(1);
        Http::assertSent(fn($request) => $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v26.0/' . self::GROUP_ID . '/join_requests'
            && $request->hasHeader('Authorization', 'Bearer TOKEN')
            && $request->data() === ['messaging_product' => 'whatsapp', 'join_requests' => [self::JOIN_REQUEST_ID]]);
    }

    public function test_rejects_a_join_request_by_its_id(): void
    {
        $answer = ['messaging_product' => 'whatsapp', 'rejected_join_requests' => [self::JOIN_REQUEST_ID]];
        $this->fakeGraph($answer);

        $result = (new MetaClient())->rejectGroupJoinRequest(self::WABA, 'TOKEN', self::GROUP_ID, self::JOIN_REQUEST_ID);

        $this->assertSame($answer, $result);
        Http::assertSentCount(1);
        Http::assertSent(fn($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://graph.facebook.com/v26.0/' . self::GROUP_ID . '/join_requests'
            && $request->data() === ['messaging_product' => 'whatsapp', 'join_requests' => [self::JOIN_REQUEST_ID]]);
    }

    public static function decisionProvider(): array
    {
        return [
            'approve' => ['approveGroupJoinRequest', 'POST', 'approved_join_requests'],
            'reject' => ['rejectGroupJoinRequest', 'DELETE', 'rejected_join_requests'],
        ];
    }

    /** Bulk in one call. Ids are trimmed and de-duplicated, in order, and an int id travels as a string. */
    #[DataProvider('decisionProvider')]
    public function test_decides_several_join_requests_in_one_call(string $method, string $verb, string $confirmedKey): void
    {
        $this->fakeGraph(['messaging_product' => 'whatsapp', $confirmedKey => ['JR-1', 'JR-2', '345']]);

        (new MetaClient())->{$method}(self::WABA, 'TOKEN', self::GROUP_ID, [' JR-1 ', 'JR-2', 'JR-1', 345]);

        Http::assertSentCount(1);
        Http::assertSent(fn($request) => $request->method() === $verb
            && $request->data() === ['messaging_product' => 'whatsapp', 'join_requests' => ['JR-1', 'JR-2', '345']]);
    }

    /**
     * A partial failure is still an HTTP 200, with `failed_join_requests`
     * next to the confirmed list. It comes back untouched so the caller can
     * decide per id. It is not flattened into an error.
     */
    #[DataProvider('decisionProvider')]
    public function test_a_partial_failure_comes_back_untouched(string $method, string $verb, string $confirmedKey): void
    {
        $answer = [
            'messaging_product' => 'whatsapp',
            $confirmedKey => ['JR-1'],
            'failed_join_requests' => [[
                'join_request_id' => 'JR-2',
                'errors' => [[
                    'code' => 131203,
                    'message' => '(#131203) Recipient has not accepted our new Terms of Service and Privacy Policy.',
                    'title' => 'Unable to add participant to group',
                    'error_data' => ['details' => 'Recipient has not accepted our new Terms of Service and Privacy Policy.'],
                ]],
            ]],
        ];
        $this->fakeGraph($answer);

        $result = (new MetaClient())->{$method}(self::WABA, 'TOKEN', self::GROUP_ID, ['JR-1', 'JR-2']);

        $this->assertSame($answer, $result);
    }

    #[DataProvider('decisionProvider')]
    public function test_a_refused_call_returns_metas_error(string $method): void
    {
        $error = ['message' => '(#100) Invalid parameter', 'type' => 'OAuthException', 'code' => 100];
        $this->fakeGraph(['error' => $error], 400);

        $result = (new MetaClient())->{$method}(self::WABA, 'TOKEN', self::GROUP_ID, self::JOIN_REQUEST_ID);

        $this->assertSame(['error' => $error], $result);
    }

    public static function invalidIdsProvider(): array
    {
        $cases = [
            'empty string' => [''],
            'blank string' => ['   '],
            'empty list' => [[]],
            'list with a blank id' => [['JR-1', ' ']],
            'list with a non-scalar id' => [['JR-1', ['JR-2']]],
            'list with null' => [['JR-1', null]],
        ];
        $out = [];
        foreach (['approveGroupJoinRequest', 'rejectGroupJoinRequest'] as $method) {
            foreach ($cases as $name => [$ids]) {
                $out["{$method}: {$name}"] = [$method, $ids];
            }
        }
        return $out;
    }

    /** Refused before any request: a dropped id would look like Meta's failure. */
    #[DataProvider('invalidIdsProvider')]
    public function test_invalid_ids_refuse_the_call_without_a_request(string $method, string|array $ids): void
    {
        Http::fake();

        $result = (new MetaClient())->{$method}(self::WABA, 'TOKEN', self::GROUP_ID, $ids);

        $this->assertArrayHasKey('error', $result);
        Http::assertNothingSent();
    }

    /**
     * Every group endpoint targets v26.0, not the v20.0 pin used by
     * message sends — Groups postdates that pin. Spot-checked on the
     * cheapest call rather than every method, to avoid a brittle
     * one-assertion-per-method wall that adds no new coverage.
     */
    public function test_group_endpoints_target_the_groups_graph_version(): void
    {
        $this->fakeGraph();

        (new MetaClient())->group(self::WABA, 'TOKEN', self::GROUP_ID);

        Http::assertSent(fn($request) => str_contains($request->url(), '/v26.0/'));
    }
}
