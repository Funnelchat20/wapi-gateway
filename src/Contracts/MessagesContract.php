<?php

namespace Funnelchat\WapiGateway\Contracts;

/**
 * sendText/sendFile/sendButtons/sendOptionList/sendLink/sendButtonLink accept a
 * provider-agnostic `typing` option (opt-in; other send methods ignore it):
 *
 *     'typing' => [
 *         'lastInboundId' => $wamid, // wamid of the contact's last inbound message (nullable)
 *         'delaySeconds'  => 3,      // desired typing duration
 *     ]
 *
 * Providers that handle the delay server-side (see handlesTypingDelayServerSide())
 * translate it to their native delayTyping param. Meta fires the typing indicator
 * right before the send when `lastInboundId` is present — best-effort: an indicator
 * failure never fails the send, it is reported under `typing_result` in the result.
 *
 * A `messageId` option quotes an existing message — the outgoing message renders as
 * a reply to it, in 1:1 chats and in groups:
 *
 *     'messageId' => $providerMessageId, // id of the message being quoted
 *
 * The quoted message's own type does not matter: the id is opaque to the provider,
 * so a text reply can quote an image. A blank value is treated as "no quote".
 *
 * The option is uniform; what each provider does with it is not. The wire field
 * differs — z-api/funapi `messageId`, Uazapi `replyid`, Meta `context.message_id` —
 * and so does the set of sends that can carry it, because the provider's endpoints
 * decide, not this contract:
 *
 *   z-api / ZApiLite / funapi — sendText, sendFile, sendLocation, sendLink,
 *     sendContact, sendPtv. sendFile is partial: it routes per file extension and
 *     send-audio is the one target that does not document the param, so quoting an
 *     audio reply is silently skipped (see ZApiClient::QUOTE_UNSUPPORTED_ACTIONS).
 *     sendPoll, sendOptionList, sendButtons, sendButtonLink and sendEvent cannot
 *     quote — those endpoints do not accept it.
 *   Uazapi — sendText, sendFile (audio included, it goes out through /send/media
 *     like every other file), sendLocation, sendContact, sendLink, sendButtons,
 *     sendButtonLink, sendOptionList and sendPoll: the last four ride /send/menu,
 *     which documents `replyid`, so they quote here even though their z-api
 *     counterparts cannot. sendEvent inherits it by composing a sendText; sendPtv
 *     is unsupported by the provider altogether.
 *   Meta — every send it implements: sendText, sendFile, sendLocation, sendContact,
 *     sendLink, sendButtons, sendButtonLink, sendOptionList. `context` belongs to
 *     the Cloud API's base message properties, so one shape covers every `type`.
 *     sendTemplate is out only because its signature carries no options bag;
 *     sendPoll, sendEvent and sendPtv are unsupported by the provider.
 *
 * Two Meta-only caveats. It renders the bubble only for a quoted message 30 days
 * old or younger — an older target is delivered as a plain message rather than
 * rejected, so the quote degrades silently. And quoting inside a group send (Groups
 * API, 2026) is not documented by Meta and has not been verified against a real
 * WABA; 1:1 is.
 */
interface MessagesContract
{
    public function sendText(string $uid, string $token, string $to, string $text, array $options = []): array;
    public function sendFile(string $uid, string $token, string $to, string $fileUrl, array $options = []): array;
    public function sendLocation(string $uid, string $token, string $to, float $lat, float $lng, array $options = []): array;
    public function sendButtons(string $uid, string $token, string $to, string $message, array $buttons, array $options = []): array;
    public function sendButtonLink(string $uid, string $token, string $to, string $message, string $url, string $label, array $options = []): array;
    public function sendOptionList(string $uid, string $token, string $to, string $message, string $buttonLabel, array $optionsList, array $extra = []): array;
    public function sendPoll(string $uid, string $token, string $to, string $message, array $pollOptions, array $options = []): array;
    public function sendLink(string $uid, string $token, string $to, string $message, string $linkUrl, array $options = []): array;
    public function sendEvent(string $uid, string $token, string $toGroupPhone, array $event, array $options = []): array;
    public function sendTemplate(string $uid, string $token, string $to, string $name, string $languageCode, array $components): array;
    public function sendPtv(string $uid, string $token, string $to, string $videoUrl, array $options = []): array;
    public function pinMessage(string $uid, string $token, string $phone, string $messageId, string $duration): array;
    /**
     * Forward an existing message to another chat. $to is the destination,
     * $messageId the message being forwarded and $sourceChat the chat it
     * currently lives in — the id alone does not locate a message, so the
     * source is required, never derived.
     *
     * The copy carries WhatsApp's "Forwarded" label and keeps the original
     * content, media included: nothing is re-uploaded by the caller.
     *
     * Supported by z-api, ZApiLite and funapi only, and the two do not share a
     * wire: z-api posts `messagePhone`, funapi `sourceChat`. Each client
     * translates, so callers pass $sourceChat and never the provider's name.
     *
     * Meta and Uazapi return an error. Neither provider exposes a forward-by-id
     * operation at all — this is a capability gap, not an unwired option, so it
     * cannot be fixed here. Uazapi's `forward` flag on /send/* only labels a
     * message the caller composes; Meta has no equivalent of any kind.
     *
     * funapi additionally honors `scheduledFor` (ISO 8601) and, like z-api,
     * `delayMessage` — the bridge caps both at 60 seconds.
     */
    public function forwardMessage(string $uid, string $token, string $to, string $messageId, string $sourceChat, array $options = []): array;

    /**
     * React to a message with an emoji, and clear that reaction again.
     *
     * $messageId is the provider's id for the message being reacted to, $phone
     * the chat it lives in. WhatsApp keeps a single reaction per sender per
     * message, so sendReaction on an already-reacted message replaces the emoji
     * rather than adding one — there is no "react twice".
     *
     * Every provider supports both operations; only the wire differs:
     *
     *   z-api / ZApiLite / funapi — two endpoints, send-reaction {phone,
     *     messageId, reaction} and send-remove-reaction {phone, messageId}.
     *     Both take an optional `delayMessage` (1-15s) via $options.
     *   Uazapi — one endpoint, /message/react {number, text, id}, where `text`
     *     is the emoji and an empty `text` performs the removal.
     *   Meta — a message of its own: `type: reaction` with
     *     `reaction: {message_id, emoji}`, and an empty `emoji` removes.
     *
     * Because two of the four providers overload "empty emoji" as the removal,
     * a blank $reaction is refused by sendReaction instead of being forwarded:
     * otherwise the same call would delete the user's reaction on Meta/Uazapi
     * and fail on z-api. removeReaction is the only way to clear one.
     *
     * funapi honors one extra option, `sender`: the JID of whoever sent the
     * message being reacted to. The bridge needs it to build the reaction and
     * falls back to the chat JID when absent, which is correct for a 1:1 but
     * not for a group — reacting to another participant's message without it
     * silently lands on nothing. z-api resolves the sender server-side and
     * ignores the option.
     */
    public function sendReaction(string $uid, string $token, string $phone, string $messageId, string $reaction, array $options = []): array;
    public function removeReaction(string $uid, string $token, string $phone, string $messageId, array $options = []): array;
    /**
     * Display a typing indicator. For Meta Cloud this is attached to the
     * mark-as-read call for an inbound message, so $messageId is the wamid of a
     * recent inbound message (within the 24h window). For Z-API/UAZAPI typing is
     * handled server-side via delayTyping and this is a no-op.
     */
    public function sendTypingIndicator(string $uid, string $token, string $messageId): array;
    /**
     * Whether the provider renders the typing indicator server-side when a send
     * carries the `typing` option (the provider holds the message and shows
     * "typing…" for the requested duration — a single call, no blocking).
     * When false (Meta), the SDK only fires the indicator alongside the send;
     * the temporal orchestration (delaying the send so the indicator is visible)
     * is the caller's responsibility, e.g. via its own delayed job.
     */
    public function handlesTypingDelayServerSide(): bool;
}
