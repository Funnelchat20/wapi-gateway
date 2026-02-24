<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Meta / WhatsApp Cloud API
    |--------------------------------------------------------------------------
    */
    'meta' => [
        // Graph API version. Override: env WAPI_META_GRAPH_VERSION (default v20.0)
        'graph_version' => env('WAPI_META_GRAPH_VERSION', 'v20.0'),
    ],
];
