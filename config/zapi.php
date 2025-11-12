<?php

return [
    'token' => env('ZAPI_TOKEN'),
    'client_token' => env('ZAPI_CLIENT_TOKEN'),
    'zapi_url' => 'https://api.z-api.io/instances/UID/token/TOKEN/ACTION',
    'subscription_url' => 'https://api.z-api.io/instances/UID/token/TOKEN/integrator/on-demand/subscription',
    'unsubscription_url' => 'https://api.z-api.io/instances/UID/token/TOKEN/integrator/on-demand/cancel',
    'on_demand_url' => 'https://api.z-api.io/instances/integrator/on-demand',
];

