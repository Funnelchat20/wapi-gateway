<?php

namespace Funnelchat\WapiGateway\Contracts;

/**
 * WhatsApp Cloud API's Block Users feature (`/{phone-number-id}/block_users`).
 *
 * Meta-only and deliberately separate from the other contracts: the
 * unofficial providers (Z-API/UAZAPI/Funapi) have no equivalent endpoint, so
 * folding this into ContactsContract would force every one of them to
 * implement a no-op.
 *
 * Meta only lets a business block a user who messaged that number in the last
 * 24 hours; otherwise the user lands in `failed_users` with error 131047.
 * Because a partial failure comes back with an outer error (139100) wrapping
 * the real per-user code, every method returns Meta's full JSON — callers read
 * `block_users.failed_users[].errors[].code`, not just `error`.
 */
interface BlockUsersContract
{
    /** Meta accepts 1 to 1000 users per call. */
    public const MAX_USERS_PER_CALL = 1000;

    /**
     * @param string[] $users Phone numbers (digits, with country code) or wa_id values.
     */
    public function blockUsers(string $uid, string $token, array $users): array;

    /**
     * @param string[] $users Same shape as blockUsers().
     */
    public function unblockUsers(string $uid, string $token, array $users): array;

    /** @param array{limit?: int, after?: string, before?: string} $options */
    public function blockedUsers(string $uid, string $token, array $options = []): array;
}
