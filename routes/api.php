<?php

use Illuminate\Support\Facades\Route;
use Funnelchat\WapiGateway\Interfaces\WhatsAppProviderControllerInterface;
use Funnelchat\WapiGateway\Interfaces\WhatsAppProviderInstanceInterface;
use Funnelchat\WapiGateway\Http\Controllers\FunapiController;
use Funnelchat\WapiGateway\Http\Controllers\ZApiInstanceController;

Route::middleware('api')->prefix('api')->group(function () {
    Route::prefix('instances')->group(function () {
    Route::post('', [WhatsAppProviderInstanceInterface::class, 'create']);
    Route::prefix('{uid}')->middleware('ensure.token.provider')->group(function () {
        Route::prefix('{token}')->group(function () {
            Route::post('subscribe', [WhatsAppProviderInstanceInterface::class, 'subscribe']);
            Route::post('unsubscribe', [WhatsAppProviderInstanceInterface::class, 'unsubscribe']);
            Route::post('get-participants/{phone}', [ZApiInstanceController::class, 'getParticipants']);
            Route::put('proxy', [WhatsAppProviderInstanceInterface::class, 'configureProxy']);
        });
        Route::controller(WhatsAppProviderControllerInterface::class)
            ->group(function () {
                Route::post('login', 'login');
                Route::get('status', 'status');
                Route::get('checkPhone', 'checkPhone');
                Route::get('qr_code', 'qrCode');
                Route::get('logout', 'logout');
                Route::get('reboot', 'reboot');
                Route::get('me', 'me');
                Route::get('contact', 'contact');
                Route::get('contacts', 'contacts');
                Route::post('send-contact', 'sendContact');
                Route::post('send-button-link', 'sendButtonLink');
                Route::post('send-message', 'sendMessage');
                Route::post('send-template', 'sendTemplates');
                Route::post('send-file', 'sendFile');
                Route::post('send-location', 'sendLocation');
                Route::post('send-poll', 'sendPoll');
                Route::post('send-buttons', 'sendButtons');
                Route::post('send-option-list', 'sendOptionList');
                Route::post('send-event', 'sendEvent');
                Route::post('send-link', 'sendLink');
                Route::get('groups-debug', 'groupsDebug');
                Route::get('groups', 'groups');
                Route::get('ad-groups', 'adGroups');
                Route::get('group', 'group');
                Route::post('group', 'createGroup');
                Route::post('update-group-name', 'updateGroupName');
                Route::post('update-group-description', 'updateGroupDescription');
                Route::post('update-group-settings', 'updateGroupSettings');
                Route::post('update-group-photo', 'updateGroupPhoto');
                Route::post('add-participants', 'addParticipants');
                Route::post('add-admins', 'addAdmins');
                Route::post('accept-invite-group', 'acceptGroupInvitation');
                Route::post('remove-participants', 'removeParticipants');
                Route::post('remove-admins', 'removeAdmins');
                Route::post('leave-group', 'leaveGroup');
                Route::post('community', 'community');
                Route::get('communities', 'communities');
                Route::get('community', 'communitiesMetadata');
                Route::post('update-community-description', 'updateCommunityDescription');
                Route::get('chats', 'chats');
                Route::post('delete-chat', 'deleteChat');
                Route::post('delete-message', 'deleteMessage');
                Route::get('group-invitation-metadata', 'groupInvitationMetadata');
                Route::get('templates', 'getTemplates');
                Route::post('update-participants-phone', 'updateParticipantsPhone');
                Route::prefix('template')->group(function () {
                    Route::get('', 'getTemplate');
                    Route::post('', 'createTemplates');
                    Route::post('update', 'updateTemplates');
                    Route::post('upload-file', 'uploadFileHeaderHandle');
                    Route::delete('', 'delete');
                });

                Route::prefix('message-queue')->group(function () {
                    Route::get('', 'showMessagesQueue');
                    Route::get('count', 'getQueueCount');
                    Route::delete('', 'deleteMessagesQueue');
                    Route::delete('all', 'clearQueue');
                });

                // Webhook configuration
                Route::put('update-webhook-received', 'updateWebhookReceived');
                Route::put('update-webhook-received-delivery', 'updateWebhookReceivedAndDelivery');
            });

        // Funapi-specific endpoints (newsletters, extra group metadata, pin)
        Route::controller(FunapiController::class)->group(function () {
            Route::post('create-newsletter', 'createNewsletter');
            Route::delete('delete-newsletter', 'deleteNewsletter');
            Route::get('newsletters', 'newsletters');
            Route::get('newsletter/metadata/{newsletterId}', 'newsletterMetadata');
            Route::post('update-newsletter-name', 'updateNewsletterName');
            Route::post('update-newsletter-description', 'updateNewsletterDescription');
            Route::post('update-newsletter-picture', 'updateNewsletterPicture');
            Route::post('group-invitation-link/{groupId}', 'groupInvitationLink');
            Route::get('light-group-metadata/{groupId}', 'lightGroupMetadata');
            Route::get('group-metadata/{groupId}', 'groupMetadata');
            Route::post('pin-message', 'pinMessage');
        });
    });
    });
});
