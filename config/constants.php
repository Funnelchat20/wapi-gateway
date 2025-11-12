<?php

return [
    'app_urls' => [
        'webhook' => env('APP_WEBHOOK_URL', ''),
        'groups' => env('APP_GROUP_URL', ''),
        'communities' => env('APP_COMMUNITIES_URL', ''),
    ],
    'provider_names' => [
        1 => 'zapi',
        2 => 'meta',
        3 => 'uazapi',
    ],
];

