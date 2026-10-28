<?php

namespace Funnelchat\WapiGateway\Contracts;

/**
 * Listing the numbers a business has blocked. Meta-only: Z-API and Funapi
 * expose no endpoint to read their blocklist, so this is separate from
 * BlockUsersContract instead of forcing them to implement a stub.
 */
interface BlockedUsersListContract
{
    /** @param array{limit?: int, after?: string, before?: string} $options */
    public function blockedUsers(string $uid, string $token, array $options = []): array;
}
