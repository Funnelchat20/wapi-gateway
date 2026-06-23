<?php

return [
    'token' => env('ZAPI_LITE_TOKEN'),
    'client_token' => env('ZAPI_LITE_CLIENT_TOKEN'),
    'base_url' => env('ZAPI_LITE_BASE_URL', 'https://api.z-api.io'),
    'subscription_url' => env('ZAPI_LITE_BASE_URL', 'https://api.z-api.io') . '/instances/UID/token/TOKEN/integrator/on-demand/subscription',
    'unsubscription_url' => env('ZAPI_LITE_BASE_URL', 'https://api.z-api.io') . '/instances/UID/token/TOKEN/integrator/on-demand/cancel',
    'on_demand_url' => env('ZAPI_LITE_BASE_URL', 'https://api.z-api.io') . '/instances/integrator/on-demand',
    'proxy_url' => env('ZAPI_LITE_PROXY_URL', env('ZAPI_LITE_BASE_URL', 'https://api.z-api.io') . '/instances/UID/token/TOKEN/integrator/configure-proxy'),

    // Webhook configuration
    'webhook_base_url' => env('WEBHOOK_BASE_URL', env('APP_URL')),

    'timeout' => env('ZAPI_LITE_TIMEOUT', 29),
    'max_attempts' => env('ZAPI_LITE_MAX_ATTEMPTS', 2),
    'retry_delay' => env('ZAPI_LITE_RETRY_DELAY', 500),
];
