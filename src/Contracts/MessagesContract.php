<?php

namespace Funnelchat\WapiGateway\Contracts;

use Funnelchat\WapiGateway\Data\MessageResultData;

interface MessagesContract
{
    public function sendText(string $uid, string $token, string $to, string $text, array $options = []): MessageResultData;
    public function sendFile(string $uid, string $token, string $to, string $fileUrl, array $options = []): MessageResultData;
    public function sendLocation(string $uid, string $token, string $to, float $lat, float $lng, array $options = []): array;
    public function sendButtons(string $uid, string $token, string $to, string $message, array $buttons, array $options = []): array;
    public function sendButtonLink(string $uid, string $token, string $to, string $message, string $url, string $label, array $options = []): array;
    public function sendOptionList(string $uid, string $token, string $to, string $message, string $buttonLabel, array $optionsList, array $extra = []): array;
    public function sendPoll(string $uid, string $token, string $to, string $message, array $pollOptions, array $options = []): array;
    public function sendLink(string $uid, string $token, string $to, string $message, string $linkUrl, array $options = []): array;
    public function sendEvent(string $uid, string $token, string $toGroupPhone, array $event, array $options = []): array;
    public function sendTemplate(string $uid, string $token, string $to, string $name, string $languageCode, array $components): array;
}
