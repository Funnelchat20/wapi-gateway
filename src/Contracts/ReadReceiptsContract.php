<?php

namespace Funnelchat\WapiGateway\Contracts;

/**
 * Read receipts ("blue ticks") on a received message, and the typing indicator
 * that the WhatsApp Cloud API attaches to that same receipt.
 *
 * Kept apart from MessagesContract on purpose. Adding abstract methods to an
 * interface that already shipped breaks every class implementing it, and this
 * has to reach the 0.22 line as a patch. A separate interface is additive:
 * nothing that implements MessagesContract today has to change, and callers
 * can detect the capability with `instanceof ReadReceiptsContract`.
 *
 * Implemented by MetaClient, ZApiClient, ZApiLiteClient and FunapiClient.
 * UazapiClient does not implement it: GatewayManager no longer resolves it.
 *
 * Results never throw and come in three shapes:
 *
 *   ['success' => true]                            — the provider confirmed it
 *   ['error' => self::UNSUPPORTED, 'message' => …] — this provider cannot do
 *                                                    it; nothing was sent
 *   ['error' => …]                                 — the provider refused it
 *                                                    or could not be reached
 *
 * What each provider supports:
 *
 *   | Method                       | Meta | Z-API | Z-API Lite | FunApi |
 *   |------------------------------|------|-------|------------|--------|
 *   | markMessageAsRead            | yes  | yes   | yes        | no     |
 *   | markMessageAsReadWithTyping  | yes  | no    | no         | no     |
 *
 * MessagesContract::sendTypingIndicator() is unchanged, so its QR clients
 * still return a silent `['success' => true]`. The new method exists so a
 * caller gets an explicit refusal where there is no indicator to show.
 */
interface ReadReceiptsContract
{
    /** `error` value when the provider has no such operation. */
    public const UNSUPPORTED = 'unsupported';

    /**
     * Mark a received message as read.
     *
     * $chat is the chat the message lives in: a phone or a group id. Z-API
     * needs it (`phone`). Meta does not, because the wamid alone locates the
     * message, so Meta ignores it. Keeping one signature for every provider
     * spares callers a branch per provider.
     *
     * Meta also marks the earlier messages of the conversation as read, and
     * only accepts messages received in the last 30 days.
     */
    public function markMessageAsRead(string $uid, string $token, string $chat, string $messageId): array;

    /**
     * Mark a received message as read AND show "typing…" to the contact.
     *
     * This is how the Cloud API does it: the read receipt carries the
     * indicator. The indicator disappears when the business answers or after
     * 25 seconds, whichever comes first. Meta asks to send it only when an
     * answer is coming.
     *
     * QR providers have no standalone typing call. They render typing
     * server-side through the `typing` option of the send methods instead.
     * Here they answer self::UNSUPPORTED and send nothing, and in particular
     * they do not mark the message as read as a side effect.
     */
    public function markMessageAsReadWithTyping(string $uid, string $token, string $chat, string $messageId): array;
}
