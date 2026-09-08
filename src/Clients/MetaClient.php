<?php

namespace Funnelchat\WapiGateway\Clients;

use Funnelchat\WapiGateway\Contracts\MessagesContract;
use Funnelchat\WapiGateway\Contracts\InstancesContract;
use Funnelchat\WapiGateway\Contracts\ContactsContract;
use Funnelchat\WapiGateway\Contracts\TemplatesContract;
use Funnelchat\WapiGateway\Helpers\WhatsAppCloudHelper;
use Funnelchat\WapiGateway\Jobs\StoreDeviceLogJob;
use Funnelchat\WapiGateway\Resources\Meta\MessageResource;
use Funnelchat\WapiGateway\Resources\Zapi\BusinessProfileResource;
use Funnelchat\WapiGateway\Traits\LogsDeviceRequests;
use Illuminate\Support\Facades\Http;

class MetaClient implements MessagesContract, InstancesContract, ContactsContract, TemplatesContract
{
    use LogsDeviceRequests;

    protected function getProviderName(): string
    {
        return 'meta';
    }
    private string $graph = 'https://graph.facebook.com/v20.0/';

    private const TYPING_INDICATOR_TIMEOUT = 5;

    /**
     * Meta's "Business-scoped User ID (BSUID) recipients are not supported for
     * this message". A BSUID destination accepts a narrower set of messages
     * than a phone (Meta excludes one-tap, zero-tap and copy-code
     * authentication templates), so a send that works for 97% of the traffic
     * can still be rejected here.
     */
    private const ERROR_UNSUPPORTED_FOR_BSUID = 131062;

    /**
     * Meta addresses a phone destination through `to` and a BSUID destination
     * through `recipient`, and it must be exactly one of them: when both are
     * present Meta resolves the phone and ignores the BSUID, so the reply
     * silently lands in the wrong conversation (or nowhere).
     *
     * Every send spreads this instead of hardcoding `'to' => $to`, which keeps
     * the phone path byte-identical to what it was before BSUID support — the
     * value is passed through untouched, trimming only on the (new) BSUID
     * branch, where isBsuid() already ignored the padding to classify it.
     */
    private function recipientField(string $to): array
    {
        return WhatsAppCloudHelper::isBsuid($to)
            ? ['recipient_type' => 'individual', 'recipient' => trim($to)]
            : ['to' => $to];
    }

    /**
     * `recipient` shipped in July 2026; $graph is pinned to v20.0 (2024-05).
     * Meta drops post-pin parameters silently, so the BSUID left here and
     * arrived with no destination — hence the generic 131000.
     */
    private const BSUID_GRAPH_VERSION = 'v26.0';

    /**
     * Routes ONLY a BSUID to the newer version; phone sends keep the pin
     * untouched. A BSUID send fails 100% today, so there is nothing working
     * to regress. Moving $graph itself is a separate change — it must happen
     * before v20.0 expires 2026-09-24, with its own testing.
     */
    private function messagesUrl(string $uid, string $to): string
    {
        return WhatsAppCloudHelper::isBsuid($to)
            ? 'https://graph.facebook.com/' . self::BSUID_GRAPH_VERSION . '/' . $uid . '/messages'
            : $this->graph . $uid . '/messages';
    }

    /**
     * Normalizes a failed send into the same ['error' => ...] shape the clients
     * have always returned, adding a machine-readable flag when Meta rejects
     * the message *type* for a BSUID recipient. Callers need to tell that apart
     * from a generic failure: retrying the identical payload will never succeed
     * — the message has to be re-sent as a type the recipient supports.
     *
     * The raw Meta error is preserved untouched under `error`, and the extra
     * keys only ever appear when the destination we actually addressed was a
     * BSUID: `error_subcode` lives in a different numbering space than `code`,
     * so a phone send that happens to come back with subcode 131062 must not
     * be mislabelled as a BSUID-unsupported send. Phone sends are unaffected.
     */
    private function sendError($res, string $to): array
    {
        $error = $res->json('error', 'Failed to send');
        $result = ['error' => $error];
        if (!is_array($error) || !WhatsAppCloudHelper::isBsuid($to)) {
            return $result;
        }
        // Both fields are checked independently, not with a `??` fallback:
        // Graph sometimes reports a generic `code` (100) and carries the real
        // reason in `error_subcode`, so the first key being present says
        // nothing about where 131062 actually is.
        $isUnsupported = (int) ($error['code'] ?? 0) === self::ERROR_UNSUPPORTED_FOR_BSUID
            || (int) ($error['error_subcode'] ?? 0) === self::ERROR_UNSUPPORTED_FOR_BSUID;
        if (!$isUnsupported) {
            return $result;
        }
        $result['error_code'] = self::ERROR_UNSUPPORTED_FOR_BSUID;
        $result['unsupported_for_bsuid'] = true;

        return $result;
    }

    public function sendText(string $uid, string $token, string $to, string $text, array $options = []): array
    {
        // Fired before $startTime so the send's logged duration excludes the
        // indicator round-trip (the indicator logs its own duration).
        $typingResult = $this->fireTypingIndicator($uid, $token, $options);
        $startTime = microtime(true);
        $url = $this->messagesUrl($uid, $to);
        $payload = [
            'messaging_product' => 'whatsapp',
            ...$this->recipientField($to),
            'type' => 'text',
            'text' => ['body' => $text],
        ];
        $payload = $this->applyQuoteOption($payload, $options);
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendText', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $url, $payload);
            return $this->withTypingResult($this->sendError($res, $to), $typingResult);
        }
        $this->logRequest('sendText', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return $this->withTypingResult(MessageResource::make($res->json()), $typingResult);
    }

    public function create(int $userId, int $deviceId, ?string $countryCode = null): array
    {
        return ['error' => 'Not supported'];
    }
    public function configureProxy(string $uid, string $token, ?string $proxyUrl): array { return ['proxy_configured' => false, 'error' => 'Proxy configuration is not supported for this provider']; }
    public function status(string $uid, string $token): array { return ['error' => 'Not supported']; }
    public function qrCode(string $uid, string $token): array { return ['error' => 'Not supported']; }
    public function extensionToken(string $uid, string $token): array { return ['error' => 'unsupported', 'message' => 'Extension token is not supported for this provider']; }
    public function sdkConnectorToken(string $uid, string $token): array { return ['error' => 'unsupported', 'message' => 'SDK connector token is not supported for this provider']; }
    public function logout(string $uid, string $token): array { return ['error' => 'Not supported']; }
    public function reboot(string $uid, string $token): array { return ['error' => 'Not supported']; }
    public function me(string $uid, string $token): array { return ['error' => 'Not supported']; }

    /**
     * Not applicable to Meta/WhatsApp Cloud API — business profile info is
     * managed through Meta's own WABA settings, not this gateway. Returns
     * the empty-defaults shape (never an exception) for contract parity.
     */
    public function businessProfile(string $uid, string $token): array
    {
        return BusinessProfileResource::make([]);
    }

    public function checkPhone(string $uid, string $token, string $phone): array { return ['error' => 'Not supported']; }
    public function checkPhoneWhatsapp(string $uid, string $token, string $phone): array { return ['error' => 'Not supported']; }
    public function checkPhonesBatch(string $uid, string $token, array $phones): array { return ['error' => 'Not supported']; }
    public function subscribe(string $uid, string $token): array { return ['error' => 'Not supported']; }
    public function unsubscribe(string $uid, string $token): array { return ['error' => 'Not supported']; }
    public function getParticipants(string $uid, string $token, string $phone): array { return []; }
    public function updateWebhookReceived(string $uid, string $token, int $userId, int $deviceId, bool $privateMessages = false): array { return []; }
    public function updateWebhookReceivedAndDelivery(string $uid, string $token, int $userId, int $deviceId): array { return []; }

    public function sendFile(string $uid, string $token, string $to, string $fileUrl, array $options = []): array
    {
        $ext = strtolower(pathinfo($fileUrl, PATHINFO_EXTENSION));
        $type = $this->mapType($ext);
        if ($type === 'invalid') return ['error' => 'Invalid file extension'];
        $typingResult = $this->fireTypingIndicator($uid, $token, $options);
        $startTime = microtime(true);
        $media = isset($options['mediaId']) ? ['id' => $options['mediaId']] : ['link' => $fileUrl];
        $payload = ['messaging_product' => 'whatsapp', ...$this->recipientField($to), 'type' => $type, $type => $media];
        if (isset($options['fileName']) && $type === 'document') $payload[$type]['filename'] = $options['fileName'];
        if (isset($options['caption']) && in_array($type, ['image', 'video', 'document'])) $payload[$type]['caption'] = $options['caption'];
        $payload = $this->applyQuoteOption($payload, $options);
        $url = $this->messagesUrl($uid, $to);
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendFile', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to, 'file_type' => $ext], $startTime, $res, $url, $payload);
            return $this->withTypingResult($this->sendError($res, $to), $typingResult);
        }
        $this->logRequest('sendFile', $uid, ['phone' => $to, 'file_type' => $ext], $startTime, $res, $url, $payload);
        return $this->withTypingResult(MessageResource::make($res->json()), $typingResult);
    }

    public function sendLocation(string $uid, string $token, string $to, float $lat, float $lng, array $options = []): array
    {
        $startTime = microtime(true);
        $payload = ['messaging_product' => 'whatsapp', ...$this->recipientField($to), 'type' => 'location', 'location' => ['latitude' => $lat, 'longitude' => $lng]];
        if (isset($options['name'])) $payload['location']['name'] = $options['name'];
        if (isset($options['address'])) $payload['location']['address'] = $options['address'];
        $payload = $this->applyQuoteOption($payload, $options);
        $url = $this->messagesUrl($uid, $to);
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendLocation', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $url, $payload);
            return $this->sendError($res, $to);
        }
        $this->logRequest('sendLocation', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function sendButtons(string $uid, string $token, string $to, string $message, array $buttons, array $options = []): array
    {
        // Validate the header media before firing the typing indicator: the
        // indicator marks the inbound as read, a side effect we must not emit
        // for a send that will never happen.
        $headerType = null;
        if (isset($options['fileUrl'])) {
            $ext = strtolower(pathinfo($options['fileUrl'], PATHINFO_EXTENSION));
            $headerType = $this->mapType($ext);
            if ($headerType === 'invalid') return ['error' => 'Invalid file extension'];
        }
        $typingResult = $this->fireTypingIndicator($uid, $token, $options);
        $startTime = microtime(true);
        $payload = [
            'messaging_product' => 'whatsapp',
            ...$this->recipientField($to),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $message],
                'action' => [
                    'buttons' => array_map(fn($b) => [
                        'type' => 'reply',
                        'reply' => [
                            'id' => $b['id'],
                            'title' => $b['label'] ?? $b['text'] ?? $b['title'] // Accept multiple formats
                        ]
                    ], $buttons)
                ]
            ]
        ];
        if ($headerType !== null) {
            $payload['interactive']['header'] = ['type' => $headerType, $headerType => ['link' => $options['fileUrl']]];
        }
        $payload = $this->applyQuoteOption($payload, $options);
        $url = $this->messagesUrl($uid, $to);
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendButtons', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $url, $payload);
            return $this->withTypingResult($this->sendError($res, $to), $typingResult);
        }
        $this->logRequest('sendButtons', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return $this->withTypingResult(MessageResource::make($res->json()), $typingResult);
    }

    public function sendButtonLink(string $uid, string $token, string $to, string $message, string $url, string $label, array $options = []): array
    {
        $typingResult = $this->fireTypingIndicator($uid, $token, $options);
        $startTime = microtime(true);
        $payload = [
            'messaging_product' => 'whatsapp',
            ...$this->recipientField($to),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'cta_url',
                'body' => ['text' => $message],
                'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => $label, 'url' => $url]]
            ]
        ];
        $payload = $this->applyQuoteOption($payload, $options);
        $requestUrl = $this->messagesUrl($uid, $to);
        $res = Http::withToken($token)->post($requestUrl, $payload);
        if ($res->failed()) {
            $this->logRequest('sendButtonLink', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $requestUrl, $payload);
            return $this->withTypingResult($this->sendError($res, $to), $typingResult);
        }
        $this->logRequest('sendButtonLink', $uid, ['phone' => $to], $startTime, $res, $requestUrl, $payload);
        return $this->withTypingResult(MessageResource::make($res->json()), $typingResult);
    }

    public function sendOptionList(string $uid, string $token, string $to, string $message, string $buttonLabel, array $optionsList, array $extra = []): array
    {
        $typingResult = $this->fireTypingIndicator($uid, $token, $extra);
        $startTime = microtime(true);
        // Support both formats: with sections or flat array
        // If first element has 'rows' key, it's already in sections format
        // Otherwise, wrap it in a section
        if (isset($optionsList[0]['rows'])) {
            // Already has sections structure
            $sections = array_map(fn($section) => [
                'title' => $section['title'] ?? 'Options',
                'rows' => array_map(fn($row) => [
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'description' => $row['description'] ?? ''
                ], $section['rows'])
            ], $optionsList);
        } else {
            // Flat array, wrap in a section
            $sections = [[
                'title' => 'Options',
                'rows' => array_map(fn($o) => [
                    'id' => $o['id'],
                    'title' => $o['title'],
                    'description' => $o['description'] ?? ''
                ], $optionsList)
            ]];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            ...$this->recipientField($to),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $message],
                'action' => [
                    'button' => $buttonLabel,
                    'sections' => $sections
                ]
            ]
        ];
        $payload = $this->applyQuoteOption($payload, $extra);
        $url = $this->messagesUrl($uid, $to);
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendOptionList', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $url, $payload);
            return $this->withTypingResult($this->sendError($res, $to), $typingResult);
        }
        $this->logRequest('sendOptionList', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return $this->withTypingResult(MessageResource::make($res->json()), $typingResult);
    }

    public function sendPoll(string $uid, string $token, string $to, string $message, array $pollOptions, array $options = []): array
    {
        return ['error' => 'Not supported'];
    }

    /**
     * The Cloud API only renders the link card when `preview_url` is set inside
     * the `text` object. Two ceilings apply, unlike Z-API/Funapi's send-link:
     * the card is scraped from the destination page's Open Graph tags (title,
     * description and image options are not accepted here), and Meta renders it
     * only when there is a prior relationship with the number (template sent
     * before, click-to-chat, or the business saved in the contact's address
     * book). The link always arrives clickable; the card is best-effort.
     *
     * The client previews the FIRST url in the body, so a $message that already
     * carries a url wins over $linkUrl — which is appended last.
     */
    public function sendLink(string $uid, string $token, string $to, string $message, string $linkUrl, array $options = []): array
    {
        $typingResult = $this->fireTypingIndicator($uid, $token, $options);
        $startTime = microtime(true);
        // Body and URL joined by a space on purpose: `conversations` persists the
        // same composition for the agent's history, so a different separator here
        // would desync what the contact sees from what the agent reads.
        $payload = [
            'messaging_product' => 'whatsapp',
            ...$this->recipientField($to),
            'type' => 'text',
            'text' => ['body' => $message . ' ' . $linkUrl, 'preview_url' => true],
        ];
        $payload = $this->applyQuoteOption($payload, $options);
        $url = $this->messagesUrl($uid, $to);
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendLink', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $url, $payload);
            return $this->withTypingResult($this->sendError($res, $to), $typingResult);
        }
        $this->logRequest('sendLink', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return $this->withTypingResult(MessageResource::make($res->json()), $typingResult);
    }

    public function sendEvent(string $uid, string $token, string $toGroupPhone, array $event, array $options = []): array
    {
        return ['error' => 'Not supported'];
    }

    /**
     * $to may be a phone or a BSUID, with one caveat the SDK cannot enforce:
     * Meta still requires a real phone number for one-tap, zero-tap and
     * copy-code authentication templates. The category is not derivable from
     * ($name, $languageCode, $components), so routing an authentication
     * template to a BSUID is the caller's call to avoid — Meta rejects it at
     * send time rather than the payload being built wrong here.
     */
    public function sendTemplate(string $uid, string $token, string $to, string $name, string $languageCode, array $components): array
    {
        $startTime = microtime(true);
        $payload = [
            'messaging_product' => 'whatsapp',
            ...$this->recipientField($to),
            'type' => 'template',
            'template' => [
                'name' => $name,
                'language' => ['code' => $languageCode],
                'components' => $components
            ]
        ];
        $url = $this->messagesUrl($uid, $to);
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendTemplate', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $url, $payload);
            return $this->sendError($res, $to);
        }
        $this->logRequest('sendTemplate', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    private function mapType(string $ext): string
    {
        return [
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image',
            'gif' => 'video', 'mp4' => 'video', 'mov' => 'video',
            'mp3' => 'audio', 'ogg' => 'audio', 'aac' => 'audio', 'm4a' => 'audio', 'opus' => 'audio', 'wav' => 'audio',
            'pdf' => 'document', 'doc' => 'document', 'docx' => 'document'
        ][$ext] ?? 'invalid';
    }

    public function sendPtv(string $uid, string $token, string $to, string $videoUrl, array $options = []): array
    {
        return ['error' => 'Not supported'];
    }

    public function pinMessage(string $uid, string $token, string $phone, string $messageId, string $duration): array
    {
        return ['error' => 'Not supported'];
    }

    /**
     * WhatsApp Cloud API has no forward operation: there is no endpoint that
     * takes a wamid and re-sends that message elsewhere, and no way to produce
     * the "Forwarded" label the app shows. The nearest equivalent is composing
     * a new message with the same content, which is an ordinary send and is
     * already covered by the send* methods — so it is not dressed up as a
     * forward here.
     */
    public function forwardMessage(string $uid, string $token, string $to, string $messageId, string $sourceChat, array $options = []): array
    {
        return ['error' => 'Not supported'];
    }

    /**
     * Cloud API models a reaction as a message of its own: `type: reaction`
     * carrying the target's wamid and the emoji. $to is the chat, $messageId
     * the wamid of the message being reacted to.
     *
     * A blank emoji is Meta's own removal signal, which is exactly the contract
     * the z-api family implements by routing to send-remove-reaction — so the
     * blank is normalized and passed through rather than refused. Whitespace
     * counts as blank: Meta would reject "   " as an invalid emoji, and the
     * caller meant "remove".
     *
     * Contrary to what this client assumed while reactions were unwired, Cloud
     * API is not limited to 1:1 here — Meta shipped group messaging in 2026
     * (`recipient_type: group`), so the group case that motivated reactions is
     * reachable. `recipientField()` already routes a BSUID correctly.
     */
    public function sendReaction(string $uid, string $token, string $to, string $messageId, string $reaction, array $options = []): array
    {
        $startTime = microtime(true);
        $emoji = trim($reaction) === '' ? '' : $reaction;
        $payload = [
            'messaging_product' => 'whatsapp',
            ...$this->recipientField($to),
            'type' => 'reaction',
            'reaction' => ['message_id' => $messageId, 'emoji' => $emoji],
        ];
        $url = $this->messagesUrl($uid, $to);
        $res = Http::withToken($token)->post($url, $payload);
        $context = ['phone' => $to, 'removing' => $emoji === ''];
        if ($res->failed()) {
            $context['error'] = $res->json('error', 'Failed to send');
            $this->logRequest('sendReaction', $uid, $context, $startTime, $res, $url, $payload);
            return $this->sendError($res, $to);
        }
        $this->logRequest('sendReaction', $uid, $context, $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function sendTypingIndicator(string $uid, string $token, string $messageId): array
    {
        $startTime = microtime(true);
        $url = $this->graph . $uid . '/messages';
        // Meta's typing indicator is delivered by marking an inbound message as
        // read with the typing_indicator flag. It requires the wamid of an
        // inbound message within the 24h customer-service window — there is no
        // standalone "send typing to a phone" payload.
        $payload = [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
            'typing_indicator' => ['type' => 'text'],
        ];
        // Short timeout: the indicator is best-effort and must not hold up a
        // send that follows it for long. Connection failures are returned as
        // ['error' => ...] (never thrown) to honor this method's contract for
        // direct callers too.
        try {
            $res = Http::withToken($token)->timeout(self::TYPING_INDICATOR_TIMEOUT)->post($url, $payload);
        } catch (\Throwable $e) {
            $this->logRequest('sendTypingIndicator', $uid, ['error' => $e->getMessage(), 'message_id' => $messageId], $startTime, null, $url, $payload);
            return ['error' => $e->getMessage()];
        }
        if ($res->failed()) {
            $this->logRequest('sendTypingIndicator', $uid, ['error' => $res->json('error', 'Failed to send typing'), 'message_id' => $messageId], $startTime, $res, $url, $payload);
            return ['error' => $res->json('error', 'Failed to send typing indicator')];
        }
        $this->logRequest('sendTypingIndicator', $uid, ['message_id' => $messageId], $startTime, $res, $url, $payload);
        return ['success' => true];
    }

    public function handlesTypingDelayServerSide(): bool
    {
        // Meta discards the indicator as soon as the message arrives, so making
        // it visible requires the caller to delay the send itself (e.g. a
        // delayed job); the SDK only fires the indicator alongside the send.
        return false;
    }

    /**
     * Fire the typing indicator right before a send when the `typing` option
     * carries the wamid of a recent inbound. Best-effort: returns null when
     * there is nothing to do, and an ['error' => ...] result (never throws)
     * when the indicator fails, so the send always proceeds.
     */
    private function fireTypingIndicator(string $uid, string $token, array $options): ?array
    {
        $lastInboundId = $options['typing']['lastInboundId'] ?? null;
        // Only a non-empty string/int is a usable wamid; anything else (null,
        // arrays, bools) means "no indicator", never an error.
        if ((!is_string($lastInboundId) && !is_int($lastInboundId)) || $lastInboundId === '') return null;
        try {
            return $this->sendTypingIndicator($uid, $token, (string) $lastInboundId);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Attach the typing indicator outcome to a send result so failures are
     * observable (`typing_result`) without ever affecting the send itself.
     */
    private function withTypingResult(array $result, ?array $typingResult): array
    {
        if (isset($typingResult['error'])) $result['typing_result'] = $typingResult;
        return $result;
    }

    /**
     * Translate the `messageId` option into the Cloud API's quote shape: a
     * top-level `context.message_id` carrying the wamid of the quoted message.
     * Unlike z-api's flat `messageId`, `context` belongs to the base message
     * properties, so every type this client builds — text, media, location,
     * contacts and interactive — takes it unchanged.
     *
     * Meta renders the bubble only for a message 30 days old or younger. Past
     * that it delivers the send as a plain message instead of failing, so a
     * stale quote degrades silently and never costs the send.
     *
     * A blank value is dropped rather than forwarded — Meta rejects an empty
     * `context.message_id` — so callers can pass a nullable field straight
     * through without branching.
     */
    private function applyQuoteOption(array $payload, array $options): array
    {
        $messageId = $options['messageId'] ?? null;
        if (is_scalar($messageId) && trim((string) $messageId) !== '') {
            $payload['context'] = ['message_id' => (string) $messageId];
        }
        return $payload;
    }

    public function addContacts(string $uid, string $token, array $contacts): array
    {
        return ['error' => 'Not supported'];
    }

    public function contact(string $uid, string $token, string $phone): array
    {
        return ['error' => 'Not supported'];
    }

    public function contacts(string $uid, string $token, array $options = []): array
    {
        return ['error' => 'Not supported'];
    }

    public function sendContact(string $uid, string $token, string $to, string $contactName, string $contactPhone, array $options = []): array
    {
        $startTime = microtime(true);
        $payload = [
            'messaging_product' => 'whatsapp',
            ...$this->recipientField($to),
            'type' => 'contacts',
            'contacts' => [[
                'name' => ['formatted_name' => $contactName, 'first_name' => $contactName],
                'phones' => [[ 'phone' => $contactPhone, 'wa_id' => $contactPhone ]]
            ]]
        ];
        $payload = $this->applyQuoteOption($payload, $options);
        $url = $this->messagesUrl($uid, $to);
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('sendContact', $uid, ['error' => $res->json('error', 'Failed to send'), 'phone' => $to], $startTime, $res, $url, $payload);
            return $this->sendError($res, $to);
        }
        $this->logRequest('sendContact', $uid, ['phone' => $to], $startTime, $res, $url, $payload);
        return MessageResource::make($res->json());
    }

    public function listTemplates(string $wabaId, string $token, array $params = []): array
    {
        $startTime = microtime(true);
        $url = $this->graph . $wabaId . '/message_templates';
        if (!empty($params)) {
            $query = [];
            if (isset($params['limit'])) $query['limit'] = $params['limit'];
            if (isset($params['after'])) $query['after'] = $params['after'];
            $url .= '?' . http_build_query($query);
        }
        $res = Http::withToken($token)->get($url);
        if ($res->failed()) {
            $this->logRequest('listTemplates', $wabaId, ['error' => $res->json('error', 'Failed to list')], $startTime, $res, $url);
            return ['error' => $res->json('error', 'Failed to list')];
        }
        $this->logRequest('listTemplates', $wabaId, [], $startTime, $res, $url);
        return $res->json();
    }

    public function getTemplate(string $wabaId, string $token, string $name): array
    {
        $startTime = microtime(true);
        $url = $this->graph . $wabaId . '/message_templates?name=' . urlencode($name);
        $res = Http::withToken($token)->get($url);
        if ($res->failed()) {
            $this->logRequest('getTemplate', $wabaId, ['error' => $res->json('error', 'Failed to get')], $startTime, $res, $url);
            return ['error' => $res->json('error', 'Failed to get')];
        }
        $this->logRequest('getTemplate', $wabaId, [], $startTime, $res, $url);
        return $res->json();
    }

    public function createTemplate(string $wabaId, string $token, array $data): array
    {
        $startTime = microtime(true);
        $header = (!empty($data['header']) || !empty($data['file']))
            ? WhatsAppCloudHelper::getHeaderTemplate($data['type'], $data['header'] ?? null, $data['file'] ?? null, $token, $this->graph)
            : [];
        $body = WhatsAppCloudHelper::getBodyTemplate($data['body'], $data['params'][0]['body'] ?? null);
        $footer = !empty($data['footer']) ? ['type' => 'FOOTER', 'text' => $data['footer']] : [];
        $buttons = !empty($data['buttons']) ? WhatsAppCloudHelper::getButtonsTemplate($data['buttons']) : [];
        $components = array_values(array_filter(!empty($header) ? [$header, $body, $footer, $buttons] : [$body, $footer, $buttons]));
        $payload = [
            'name' => $data['name'],
            'language' => $data['language_code'],
            'category' => $data['category'],
            'components' => $components
        ];
        if (array_key_exists('allow_category_change', $data)) {
            $payload['allow_category_change'] = $data['allow_category_change'];
        }
        $url = $this->graph . $wabaId . '/message_templates';
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('createTemplate', $wabaId, ['error' => $res->json('error', 'Failed to create')], $startTime, $res, $url, $payload);
            return ['error' => $res->json('error', 'Failed to create')];
        }
        $this->logRequest('createTemplate', $wabaId, [], $startTime, $res, $url, $payload);
        return $res->json();
    }

    public function updateTemplate(string $templateUid, string $token, array $data): array
    {
        $startTime = microtime(true);
        $header = (!empty($data['header']) || !empty($data['file']))
            ? WhatsAppCloudHelper::getHeaderTemplate($data['type'], $data['header'] ?? null, $data['file'] ?? null, $token, $this->graph)
            : [];
        $body = WhatsAppCloudHelper::getBodyTemplate($data['body'], $data['params'][0]['body'] ?? null);
        $footer = !empty($data['footer']) ? ['type' => 'FOOTER', 'text' => $data['footer']] : [];
        $buttons = !empty($data['buttons']) ? WhatsAppCloudHelper::getButtonsTemplate($data['buttons']) : [];
        $components = array_values(array_filter(!empty($header) ? [$header, $body, $footer, $buttons] : [$body, $footer, $buttons]));
        $payload = [
            'category' => $data['category'],
            'components' => $components
        ];
        $url = $this->graph . $templateUid;
        $res = Http::withToken($token)->post($url, $payload);
        if ($res->failed()) {
            $this->logRequest('updateTemplate', $templateUid, ['error' => $res->json('error', 'Failed to update')], $startTime, $res, $url, $payload);
            return ['error' => $res->json('error', 'Failed to update')];
        }
        $this->logRequest('updateTemplate', $templateUid, [], $startTime, $res, $url, $payload);
        return $res->json();
    }

    public function deleteTemplate(string $wabaId, string $token, string $name, string $uid): array
    {
        $startTime = microtime(true);
        $url = $this->graph . $wabaId . '/message_templates';
        $res = Http::withToken($token)->delete($url, ['name' => $name, 'hsm_id' => $uid]);
        if ($res->failed()) {
            $this->logRequest('deleteTemplate', $wabaId, ['error' => $res->json('error', 'Failed to delete')], $startTime, $res, $url);
            return ['error' => $res->json('error', 'Failed to delete')];
        }
        $this->logRequest('deleteTemplate', $wabaId, [], $startTime, $res, $url);
        return $res->json();
    }

    public function uploadMedia(string $uid, string $token, string $fileKey, ?string $mime = null): array
    {
        $startTime = microtime(true);
        $fileUrl = config('wapi-gateway.aws_bucket_url') . '/' . $fileKey;
        $fileContent = @file_get_contents($fileUrl);
        if ($fileContent === false) return ['error' => 'File not found'];
        if ($mime === null) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($fileContent) ?: 'application/octet-stream';
        }
        $url = $this->graph . $uid . '/media';
        $payload = ['messaging_product' => 'whatsapp', 'type' => $mime, 'file_key' => $fileKey];
        $res = Http::withToken($token)
            ->attach('file', $fileContent, basename($fileKey), ['Content-Type' => $mime])
            ->post($url, ['messaging_product' => 'whatsapp', 'type' => $mime]);
        if ($res->failed() || $res->json('error')) {
            $this->logRequest('uploadMedia', $uid, ['error' => $res->json('error', 'Failed to upload'), 'file_key' => $fileKey], $startTime, $res, $url, $payload);
            return ['error' => $res->json('error', 'Failed to upload')];
        }
        $this->logRequest('uploadMedia', $uid, ['file_key' => $fileKey, 'mime' => $mime], $startTime, $res, $url, $payload);
        return $res->json();
    }

    public function uploadHeaderHandle(string $token, string $fileKey): array
    {
        $startTime = microtime(true);
        $appId = config('wapi-gateway.meta_app_id');
        $fileUrl = config('wapi-gateway.aws_bucket_url') . '/' . $fileKey;
        $fileContent = @file_get_contents($fileUrl);
        if ($fileContent === false) return ['error' => 'File not found'];
        $fileSize = strlen($fileContent);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $fileMimeType = $finfo->buffer($fileContent);
        $sessionUrl = $this->graph . $appId . '/uploads?file_length=' . $fileSize . '&file_type=' . $fileMimeType;
        $session = Http::withToken($token)->post($sessionUrl, []);
        if ($session->failed() || $session->json('error')) {
            $this->logRequest('uploadHeaderHandle', $appId, ['error' => $session->json('error')], $startTime, $session, $sessionUrl);
            return ['error' => $session->json('error')];
        }
        $sessionId = $session->json('id');
        $uploadUrl = $this->graph . $sessionId;
        $response = Http::withHeaders(['Authorization' => 'OAuth ' . $token, 'file_offset' => 0, 'Content-Type' => $fileMimeType])
            ->withBody($fileContent, 'application/octet-stream')
            ->post($uploadUrl);
        if ($response->failed() || $response->json('error')) {
            $this->logRequest('uploadHeaderHandle', $appId, ['error' => $response->json('error')], $startTime, $response, $uploadUrl);
            return ['error' => $response->json('error')];
        }
        $this->logRequest('uploadHeaderHandle', $appId, [], $startTime, $response, $uploadUrl);
        return $response->json();
    }
}
