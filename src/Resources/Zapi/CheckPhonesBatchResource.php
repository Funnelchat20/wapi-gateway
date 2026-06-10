<?php

namespace Funnelchat\WapiGateway\Resources\Zapi;

class CheckPhonesBatchResource
{
    /**
     * Normalize z-api's batch phone-exists response.
     *
     * z-api returns a list of objects: {exists, inputPhone, outputPhone, lid}.
     * We expose one entry per input phone: {phone, result, lid} (lid raw, may carry
     * the "@lid" suffix — the consumer normalizes to numeric-pure).
     */
    public static function make(array $data): array
    {
        // Tolerate either a bare list or a payload wrapped under a key.
        $entries = array_is_list($data) ? $data : ($data['phones'] ?? $data['results'] ?? []);

        return array_values(array_map(static function ($entry): array {
            $entry = is_array($entry) ? $entry : [];

            return [
                'phone' => $entry['inputPhone'] ?? $entry['phone'] ?? null,
                'result' => $entry['exists'] ?? false,
                'lid' => $entry['lid'] ?? null,
            ];
        }, $entries));
    }
}
