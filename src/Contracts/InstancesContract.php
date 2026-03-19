<?php

namespace Funnelchat\WapiGateway\Contracts;

interface InstancesContract
{
    public function create(int $userId, int $deviceId): array;
    public function status(string $uid, string $token): array;
    public function qrCode(string $uid, string $token): array;
    public function logout(string $uid, string $token): array;
    public function reboot(string $uid, string $token): array;
    public function me(string $uid, string $token): array;
    public function checkPhone(string $uid, string $token, string $phone): array;
    public function subscribe(string $uid, string $token): array;
    public function unsubscribe(string $uid, string $token): array;
    public function getParticipants(string $uid, string $token, string $phone): array;

    public function updateWebhookReceived(string $uid, string $token, int $userId, int $deviceId, bool $privateMessages = false): array;

    public function updateWebhookReceivedAndDelivery(string $uid, string $token, int $userId, int $deviceId): array;
}
