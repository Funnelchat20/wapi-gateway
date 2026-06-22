<?php

return [
    'token' => env('ZAPI_TOKEN'),
    'client_token' => env('ZAPI_CLIENT_TOKEN'),
    'base_url' => env('ZAPI_BASE_URL', 'https://api.z-api.io'),
    'subscription_url' => env('ZAPI_BASE_URL', 'https://api.z-api.io') . '/instances/UID/token/TOKEN/integrator/on-demand/subscription',
    'unsubscription_url' => env('ZAPI_BASE_URL', 'https://api.z-api.io') . '/instances/UID/token/TOKEN/integrator/on-demand/cancel',
    'on_demand_url' => env('ZAPI_BASE_URL', 'https://api.z-api.io') . '/instances/integrator/on-demand',
    'proxy_url' => env('ZAPI_PROXY_URL', env('ZAPI_BASE_URL', 'https://api.z-api.io') . '/instances/UID/token/TOKEN/integrator/configure-proxy'),

    // Webhook configuration
    'webhook_base_url' => env('WEBHOOK_BASE_URL', env('APP_URL')),

    /*
    |--------------------------------------------------------------------------
    | HTTP Request Configuration
    |--------------------------------------------------------------------------
    |
    | Configure timeout and retry behavior for Z-API HTTP requests.
    | - timeout: Maximum time to wait for a response (in seconds)
    | - max_attempts: Maximum number of retry attempts for failed requests
    | - retry_delay: Delay between retries (in milliseconds)
    |
    */
    'timeout' => env('ZAPI_TIMEOUT', 29),
    'max_attempts' => env('ZAPI_MAX_ATTEMPTS', 2),
    'retry_delay' => env('ZAPI_RETRY_DELAY', 500),
];

