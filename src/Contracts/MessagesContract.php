<?php

namespace Funnelchat\WapiGateway\Contracts;

/**
 * sendText/sendFile/sendButtons/sendOptionList accept a provider-agnostic
 * `typing` option (opt-in; other send methods ignore it):
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
