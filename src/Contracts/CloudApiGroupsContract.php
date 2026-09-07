<?php

namespace Funnelchat\WapiGateway\Contracts;

/**
 * WhatsApp Cloud API's native Groups feature (Official Business Account only).
 *
 * Deliberately separate from GroupsContract: that interface models the
 * unofficial-provider group (Z-API/UAZAPI/Funapi) — direct participant/admin
 * addition, communities, newsletters, delete-message — none of which exist on
 * the Cloud API. Groups here are invite-link + join-request only, capped at 8
 * participants, and the creating business number is always the sole admin
 * (Meta exposes no endpoint to change that). Extending GroupsContract instead
 * would force every unofficial-provider client to implement methods (invite
 * templates, join requests) that make no sense for them.
 */
interface CloudApiGroupsContract
{
    /**
     * Create a group on this business phone number.
     *
     * @param array $options Passed through into the request body alongside
     *   `name` (e.g. `description`) — kept a bag rather than named
     *   parameters because Meta's accepted fields at creation time are not
     *   fully confirmed against the Graph API reference; validate against a
     *   real Cloud API Groups-enabled number before relying on any key here
     *   beyond `name`.
     */
    public function createGroup(string $uid, string $token, string $name, array $options = []): array;

    public function deleteGroup(string $uid, string $token, string $groupId): array;

    /** @param string[] $fields Graph `fields` query param, e.g. ['name', 'participants'] */
    public function group(string $uid, string $token, string $groupId, array $fields = []): array;

    /** @param array{limit?: int, after?: int} $options */
    public function groups(string $uid, string $token, array $options = []): array;

    /** @param array $settings Merged directly into the POST body (e.g. `name`, `description`). */
    public function updateGroupSettings(string $uid, string $token, string $groupId, array $settings): array;

    public function getGroupInviteLink(string $uid, string $token, string $groupId): array;

    public function resetGroupInviteLink(string $uid, string $token, string $groupId): array;

    /**
     * Send an already-approved template message whose body carries the group
     * invite link via a `group_id` parameter — Meta resolves it to the
     * clickable invite link at delivery time. $to accepts a phone or a BSUID,
     * same as every other send on this contract's sibling MessagesContract.
     */
    public function sendGroupInviteTemplate(string $uid, string $token, string $to, string $templateName, string $languageCode, string $groupId): array;

    /**
     * @param string $participant A phone number or a BSUID — the
     *   implementation decides whether Meta expects `user` or `user_id`.
     */
    public function removeGroupParticipant(string $uid, string $token, string $groupId, string $participant): array;

    public function getGroupJoinRequests(string $uid, string $token, string $groupId): array;

    /** @param string $participant A phone number or a BSUID, same as removeGroupParticipant(). */
    public function approveGroupJoinRequest(string $uid, string $token, string $groupId, string $participant): array;

    /** @param string $participant A phone number or a BSUID, same as removeGroupParticipant(). */
    public function rejectGroupJoinRequest(string $uid, string $token, string $groupId, string $participant): array;
}
