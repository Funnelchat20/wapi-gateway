<?php

namespace Funnelchat\WapiGateway\Contracts;

/**
 * Block / unblock WhatsApp users, implemented by every provider: Meta Cloud API
 * (`/{phone-number-id}/block_users`), Z-API, Z-API Lite and Funapi
 * (`modify-blocked`, one phone per request).
 *
 * Every implementation answers in the shape Meta uses, so callers handle one
 * format: `block_users.added_users[]` (block) or `block_users.removed_users[]`
 * (unblock), plus `block_users.failed_users[]` with `errors[].code|message`
 * for the ones that did not go through. When any user failed there is also a
 * top-level `error` key; a fully successful call has none.
 *
 * Meta only lets a business block a user who messaged that number in the last
 * 24 hours; otherwise the user lands in `failed_users` with error 131047.
 * Meta wraps a partial failure in an outer error (139100), so callers read
 * `block_users.failed_users[].errors[].code`, not only `error`.
 *
 * A timeout or connection failure throws (Laravel's ConnectionException): the
 * outcome is unknown, so it is not reported as a failed user.
 */
interface BlockUsersContract
{
    /** Meta accepts 1 to 1000 users per call. */
    public const MAX_USERS_PER_CALL = 1000;

    /**
     * Z-API-family providers take one phone per request, so a batch is a
     * sequential loop; keep it short.
     */
    public const MAX_USERS_PER_CALL_SEQUENTIAL = 50;

    /**
     * @param string[] $users Phone numbers (digits, with country code) or wa_id values.
     */
    public function blockUsers(string $uid, string $token, array $users): array;

    /**
     * @param string[] $users Same shape as blockUsers().
     */
    public function unblockUsers(string $uid, string $token, array $users): array;
}
