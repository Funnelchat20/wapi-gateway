<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\MetaClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

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

    public function test_approves_a_join_request_by_phone(): void
    {
        $this->fakeGraph(['success' => true]);

        (new MetaClient())->approveGroupJoinRequest(self::WABA, 'TOKEN', self::GROUP_ID, self::PHONE);

        Http::assertSent(fn($request) => $request->method() === 'POST'
            && str_ends_with(explode('?', $request->url())[0], self::GROUP_ID . '/join_requests')
            && $request->data() === ['participants' => [['user' => self::PHONE]]]);
    }

    public function test_approves_a_join_request_by_bsuid(): void
    {
        $this->fakeGraph(['success' => true]);

        (new MetaClient())->approveGroupJoinRequest(self::WABA, 'TOKEN', self::GROUP_ID, self::BSUID);

        Http::assertSent(fn($request) => $request->data() === ['participants' => [['user_id' => self::BSUID]]]);
    }

    public function test_rejects_a_join_request(): void
    {
        $this->fakeGraph(['success' => true]);

        (new MetaClient())->rejectGroupJoinRequest(self::WABA, 'TOKEN', self::GROUP_ID, self::PHONE);

        Http::assertSent(fn($request) => $request->method() === 'DELETE'
            && str_ends_with(explode('?', $request->url())[0], self::GROUP_ID . '/join_requests')
            && $request->data() === ['participants' => [['user' => self::PHONE]]]);
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
