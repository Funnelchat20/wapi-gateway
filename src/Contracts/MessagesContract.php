<?php

namespace Funnelchat\WapiGateway\Contracts;

interface MessagesContract
{
    public function sendText(string $uid, string $token, string $to, string $text, array $options = []): array;
}

