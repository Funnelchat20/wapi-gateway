<?php

return [
    'token' => env('FUNAPI_TOKEN'),
    'client_token' => env('FUNAPI_CLIENT_TOKEN'),
    'base_url' => env('FUNAPI_BASE_URL'),
    'on_demand_url' => env('FUNAPI_BASE_URL') . '/instances/integrator/on-demand',
    'subscription_url' => env('FUNAPI_BASE_URL') . '/instances/UID/token/TOKEN/integrator/on-demand/subscription',
    'unsubscription_url' => env('FUNAPI_BASE_URL') . '/instances/UID/token/TOKEN/integrator/on-demand/cancel',

    // Webhook configuration
    'webhook_base_url' => env('WEBHOOK_BASE_URL', env('APP_URL')),
    // Optional: separate base URL for messageStatusCallbackUrl. Falls back to webhook_base_url when unset.
    'webhook_status_base_url' => env('WEBHOOK_STATUS_BASE_URL', env('WEBHOOK_BASE_URL', env('APP_URL'))),

    /*
    |--------------------------------------------------------------------------
    | HTTP Request Configuration
    |--------------------------------------------------------------------------
    |
    | Configure timeout and retry behavior for Funapi HTTP requests.
    | - timeout: Maximum time to wait for a response (in seconds)
    | - max_attempts: Maximum number of retry attempts for failed requests
    | - retry_delay: Delay between retries (in milliseconds)
    |
    */
    'timeout' => env('FUNAPI_TIMEOUT', 29),
    'max_attempts' => env('FUNAPI_MAX_ATTEMPTS', 2),
    'retry_delay' => env('FUNAPI_RETRY_DELAY', 500),
];
