<?php

return [
    'database_connection' => env('WAPI_GATEWAY_DATABASE_CONNECTION', 'mysql'),
    'logging_enabled' => env('WAPI_GATEWAY_LOGGING_ENABLED', false),
    'device_logs_queue' => env('WAPI_GATEWAY_DEVICE_LOGS_QUEUE', 'device-logs'),
];
