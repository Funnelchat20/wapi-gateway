<?php

namespace Funnelchat\WapiGateway\Interfaces;

use Illuminate\Http\Request;

interface WhatsAppProviderControllerInterface
{
    public function status(Request $request);
    public function checkPhone(Request $request);
    public function qrCode(Request $request);
    public function logout(Request $request);
    public function reboot(Request $request);
    public function me(Request $request);
    public function contact(Request $request);
    public function contacts(Request $request);
    public function sendContact(Request $request);
    public function sendMessage(Request $request);
    public function sendFile(Request $request);
    public function sendButtonLink(Request $request);
    public function sendButtons(Request $request);
    public function sendPoll(Request $request);
    public function sendEvent(Request $request);
    public function sendLink(Request $request);
    public function sendLocation(Request $request);
    public function groups(Request $request);
    public function adGroups(Request $request);
    public function group(Request $request);
    public function createGroup(Request $request);
    public function sendOptionList(Request $request);
    public function updateGroupName(Request $request);
    public function updateGroupDescription(Request $request);
    public function updateGroupSettings(Request $request);
    public function updateGroupPhoto(Request $request);
    public function addParticipants(Request $request);
    public function addAdmins(Request $request);
    public function removeParticipants(Request $request);
    public function removeAdmins(Request $request);
    public function leaveGroup(Request $request);
    public function community(Request $request);
    public function communities(Request $request);
    public function communitiesMetadata(Request $request);
    public function chats(Request $request);
    public function deleteChat(Request $request);
    public function deleteMessage(Request $request);
    public function groupInvitationMetadata(Request $request);
    public function showMessagesQueue(Request $request);
    public function getQueueCount();
    public function deleteMessagesQueue(Request $request);
    public function clearQueue();
}
