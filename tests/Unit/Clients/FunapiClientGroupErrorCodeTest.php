<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Clients;

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;

/**
 * Funapi group participant/admin failures must be classified by the response's
 * `code`, never by its `error` message.
 *
 * Measured in production (device_logs, 2026-09-22..29): the single message
 * "group participant operation rejected" covered four distinct codes, one
 * benign (participant_already_in_group, x26) and three genuine failures
 * (group_resource_not_found x8, group_action_forbidden x1,
 * group_upstream_failure x1). A consumer keying off the message to treat
 * "already a participant" as success would silently swallow all ten failures.
 */
class FunapiClientGroupErrorCodeTest extends TestCase
{
    private FunapiClient $client;

    private const URL = 'https://api.whatsgo.wa-api.io/instances/UID/token/TOKEN/add-participant';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'funapi.base_url' => 'https://api.whatsgo.wa-api.io',
            'funapi.client_token' => 'client-token',
        ]);

        // logRequest() dispatches StoreDeviceLogJob; keep it off the wire.
        Queue::fake();

        $this->client = new FunapiClient();
    }

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    private function addParticipantsReturning(array $body, int $status): array
    {
        Http::fake([self::URL => Http::response($body, $status)]);

        return $this->client->addParticipants('UID', 'TOKEN', '120363411822692473', ['573125945563']);
    }

    /**
     * The regression this whole change exists to prevent.
     *
     * Same `error` string as the benign 409 below, but the code says the group
     * does not exist. It must NOT come back as `already_participant`, or the
     * caller marks a failed operation as done.
     */
    public function test_same_message_with_different_code_is_not_already_participant(): void
    {
        $result = $this->addParticipantsReturning([
            'code' => 'group_resource_not_found',
            'error' => 'group participant operation rejected',
        ], 404);

        $this->assertSame('group_not_found', $result['error']);
        $this->assertNotSame('already_participant', $result['error']);
        $this->assertSame('group_resource_not_found', $result['code']);
        $this->assertSame(404, $result['status']);
    }

    public function test_already_in_group_maps_to_already_participant(): void
    {
        $result = $this->addParticipantsReturning([
            'code' => 'participant_already_in_group',
            'error' => 'group participant operation rejected',
            'details' => ['action' => 'add', 'failures' => [['code' => 'upstream_rejected', 'index' => 0, 'reason' => 'already_participant']]],
        ], 409);

        $this->assertSame('already_participant', $result['error']);
        $this->assertSame('participant_already_in_group', $result['code']);
        $this->assertSame(409, $result['status']);
    }

    /**
     * Third collision on that same message — a genuine permission failure.
     *
     * One fake per test: a second Http::fake() in the same method does not
     * override the first stub, so these cases must not share a test body.
     */
    public function test_forbidden_keeps_its_own_identity(): void
    {
        $result = $this->addParticipantsReturning([
            'code' => 'group_action_forbidden',
            'error' => 'group participant operation rejected',
        ], 403);

        $this->assertSame('group_forbidden', $result['error']);
        $this->assertSame(403, $result['status']);
    }

    /**
     * Fourth collision on that same message — a genuine upstream failure.
     */
    public function test_upstream_failure_keeps_its_own_identity(): void
    {
        $result = $this->addParticipantsReturning([
            'code' => 'group_upstream_failure',
            'error' => 'group participant operation rejected',
        ], 502);

        $this->assertSame('upstream_failure', $result['error']);
        $this->assertSame(502, $result['status']);
    }

    public function test_rate_limited_is_distinguishable(): void
    {
        $result = $this->addParticipantsReturning([
            'code' => 'group_rate_limited',
            'error' => 'whatsapp rejected group operation',
        ], 429);

        $this->assertSame('rate_limited', $result['error']);
        $this->assertSame(429, $result['status']);
    }

    /**
     * The 503 family carries no `code`; it must fall back to the message table
     * rather than through the inherited z-api vocabulary, which matches none of
     * Funapi's strings.
     */
    public function test_codeless_session_failures_map_to_not_connected(): void
    {
        $dead = $this->addParticipantsReturning(['error' => 'whatsapp client not connected'], 503);

        $this->assertSame('not_connected', $dead['error']);
        $this->assertNull($dead['code']);
    }

    public function test_dropped_connection_also_maps_to_not_connected(): void
    {
        $dropped = $this->addParticipantsReturning([
            'error' => 'whatsapp connection dropped before the group request completed',
        ], 503);

        $this->assertSame('not_connected', $dropped['error']);
        $this->assertNull($dropped['code']);
    }

    /**
     * Funapi says "phones are required" when it cannot parse a phone as E.164 —
     * the array is present and non-empty. The message is misleading, so it is
     * mapped to something that says what actually happened.
     */
    public function test_unparseable_phone_maps_to_invalid_phone_format(): void
    {
        $result = $this->addParticipantsReturning(['error' => 'phones are required'], 400);

        $this->assertSame('invalid_phone_format', $result['error']);
        $this->assertSame(400, $result['status']);
    }

    /**
     * An unmapped code must not be silently reshaped into a known one; the raw
     * message passes through so the consumer treats it as a terminal unknown.
     */
    public function test_unknown_code_falls_through_without_inventing_a_classification(): void
    {
        $result = $this->addParticipantsReturning([
            'code' => 'some_code_funapi_added_yesterday',
            'error' => 'a message this bridge has never seen',
        ], 418);

        $this->assertSame('a message this bridge has never seen', $result['error']);
        $this->assertSame('some_code_funapi_added_yesterday', $result['code']);
        $this->assertNotContains($result['error'], ['already_participant', 'group_not_found', 'rate_limited']);
    }

    public function test_success_response_is_unchanged(): void
    {
        Http::fake([self::URL => Http::response(['success' => true], 200)]);

        $result = $this->client->addParticipants('UID', 'TOKEN', '120363411822692473', ['573125945563']);

        $this->assertSame(['success' => true], $result);
        $this->assertArrayNotHasKey('error', $result);
    }

    /**
     * The sibling group operations share the helper, so the contract must hold
     * for them too — `addAdmins` is the one the pre-add path leans on.
     */
    public function test_add_admins_shares_the_same_contract(): void
    {
        Http::fake([
            'https://api.whatsgo.wa-api.io/instances/UID/token/TOKEN/add-admin' => Http::response([
                'code' => 'participant_already_in_group',
                'error' => 'group participant operation rejected',
            ], 409),
        ]);

        $result = $this->client->addAdmins('UID', 'TOKEN', '120363411822692473', ['573125945563']);

        $this->assertSame('already_participant', $result['error']);
        $this->assertSame('participant_already_in_group', $result['code']);
    }
}
