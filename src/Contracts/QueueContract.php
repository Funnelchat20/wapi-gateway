<?php

namespace Funnelchat\WapiGateway\Contracts;

interface QueueContract
{
    public function showQueue(string $uid, string $token, array $options = []): array;
    public function queueCount(string $uid, string $token): array;
    public function deleteQueueMessage(string $uid, string $token, string $messageQueueUid): array;
    public function clearQueue(string $uid, string $token): array;
}

