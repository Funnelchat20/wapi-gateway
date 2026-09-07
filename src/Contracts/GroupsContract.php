<?php

namespace Funnelchat\WapiGateway\Contracts;

interface GroupsContract
{
    public function groups(string $uid, string $token, array $options = []): array;
    public function adGroups(string $uid, string $token, array $options = []): array;
    public function group(string $uid, string $token, string $id): array;
    public function createGroup(string $uid, string $token, string $name, array $participants, array $options = []): array;
    public function updateGroupName(string $uid, string $token, string $id, string $name): array;
    public function updateGroupDescription(string $uid, string $token, string $id, string $description): array;
    public function updateGroupSettings(string $uid, string $token, string $id, bool $adminOnlyMessage, bool $adminOnlySettings): array;
    public function updateGroupPhoto(string $uid, string $token, string $id, string $photoUrl): array;
    public function addParticipants(string $uid, string $token, string $id, array $phones): array;
    public function addAdmins(string $uid, string $token, string $id, array $phones): array;
    public function addCommunityAdmins(string $uid, string $token, string $id, array $phones): array;
    public function removeParticipants(string $uid, string $token, string $id, array $phones): array;
    public function removeAdmins(string $uid, string $token, string $id, array $phones): array;
    public function removeCommunityAdmins(string $uid, string $token, string $id, array $phones): array;
    public function removeCommunityParticipant(string $uid, string $token, string $id, array $phones): array;
    public function deactivateCommunity(string $uid, string $token, string $communityId): array;
    public function leaveGroup(string $uid, string $token, string $id): array;
    public function communities(string $uid, string $token, array $options = []): array;
    public function communitiesMetadata(string $uid, string $token, string $id): array;
    public function community(string $uid, string $token, array $data): array;
    public function updateCommunityDescription(string $uid, string $token, string $id, string $description): array;
    public function groupInvitationMetadata(string $uid, string $token, string $url): array;
    public function chats(string $uid, string $token, array $options = []): array;

    /**
     * Fetch a SINGLE chat's metadata by its provider-side chat id.
     *
     * `$chatId` is passed through verbatim, so the caller supplies whatever
     * form the provider expects — a bare phone for a 1:1 chat, or the
     * `{groupId}-group` form for a group. This is deliberate: unlike
     * `groupMetadata()`, which appends `-group` itself, this method is not
     * group-specific and must not assume a suffix.
     *
     * Motivation (communities issue #1238): `group-metadata` does NOT return
     * the group's profile picture under any field name — verified across
     * 531,827 successful production responses. The picture only ever came from
     * this endpoint, as `profileThumbnail`. `group()` already performs that
     * second call internally, but it funnels the result through
     * `GroupResource`, which flattens participants to bare phones and drops
     * `lid` and `suspended` — both load-bearing for consumers. This method
     * exposes the chat read on its own so a caller can enrich a
     * `groupMetadata()` response without losing anything.
     *
     * Returns the provider's raw payload (same convention as `chats()`), or
     * `['error' => string]`. Note that `profileThumbnail` URLs are short-lived
     * (~9 days, per the `oe` query parameter): re-host the image, do not
     * persist the URL.
     */
    public function chat(string $uid, string $token, string $chatId): array;

    public function deleteChat(string $uid, string $token, string $phone): array;
    public function deleteMessage(string $uid, string $token, string $messageId, string $phone, bool $owner): array;
    public function deleteMessagesConcurrently(string $uid, string $token, array $deleteRequests): array;
    public function createNewsletter(string $uid, string $token, string $name, string $description): array;
    public function updateNewsletterName(string $uid, string $token, string $id, string $name): array;
    public function updateNewsletterDescription(string $uid, string $token, string $id, string $description): array;
    public function updateNewsletterPicture(string $uid, string $token, string $id, string $photoUrl): array;
    public function newsletters(string $uid, string $token): array;
    public function newsletterMetadata(string $uid, string $token, string $id): array;
    public function groupInvitationLink(string $uid, string $token, string $groupId): array;
    public function lightGroupMetadata(string $uid, string $token, string $groupId): array;
    public function groupMetadata(string $uid, string $token, string $groupId): array;
    public function acceptGroupInvitation(string $uid, string $token, string $invitationUrl): array;
}
