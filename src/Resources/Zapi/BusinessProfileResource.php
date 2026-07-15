<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class BusinessProfileResource
{
    /**
     * Maps Z-API's `GET /instances/{id}/token/{token}/business/profile`
     * response. Only meaningful for business accounts.
     *
     * The upstream `websites` array is normalized to the singular `website`
     * key (kept as an array — the device-quality consumer in accounts
     * expects `website` as an array, not the plural `websites`).
     *
     * Called with an empty array (`[]`) when the account is not a business
     * account, the upstream response was empty, or the request failed —
     * always returns the full shape with empty/false defaults so callers
     * never need to null-check individual keys.
     */
    public static function make(array $data): array
    {
        return [
            "description" => $data['description'] ?? "",
            "website" => $data['websites'] ?? [],
            "email" => $data['email'] ?? "",
            "address" => $data['address'] ?? "",
            "categories" => $data['categories'] ?? [],
            "businessHours" => $data['businessHours'] ?? [],
            "hasCoverPhoto" => $data['hasCoverPhoto'] ?? false,
        ];
    }
}
