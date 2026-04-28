<?php

namespace Funnelchat\WapiGateway\Contracts;

interface QueueContract
{
    /**
     * @param array{cursor?: ?string, pageSize?: int} $options
     * @return array{
     *   messages: list<array{
     *     ZaapId: ?string,
     *     messageId: ?string,
     *     message: string,
     *     created: ?string,
     *     phone: ?string,
     *     fileUrl: string,
     *     caption: string
     *   }>,
     *   cursor: ?string,
     *   hasMore: bool
     * }|array{error: string}
     */
    public function showQueue(string $uid, string $token, array $options = []): array;
    public function queueCount(string $uid, string $token): array;
    public function deleteQueueMessage(string $uid, string $token, string $messageQueueUid): array;
    public function clearQueue(string $uid, string $token): array;
}
