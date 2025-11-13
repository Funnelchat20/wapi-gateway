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
    public function removeParticipants(string $uid, string $token, string $id, array $phones): array;
    public function removeAdmins(string $uid, string $token, string $id, array $phones): array;
    public function leaveGroup(string $uid, string $token, string $id): array;
    public function communities(string $uid, string $token, array $options = []): array;
    public function communitiesMetadata(string $uid, string $token, string $id): array;
    public function community(string $uid, string $token, array $data): array;
    public function groupInvitationMetadata(string $uid, string $token, string $url): array;
    public function chats(string $uid, string $token, array $options = []): array;
    public function deleteChat(string $uid, string $token, string $phone): array;
    public function deleteMessage(string $uid, string $token, string $messageId, string $phone, bool $owner): array;
}
