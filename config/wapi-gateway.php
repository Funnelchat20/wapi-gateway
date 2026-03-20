<?php

return [
    'database_connection' => env('WAPI_GATEWAY_DATABASE_CONNECTION', 'mysql'),
    'logging_enabled' => env('WAPI_GATEWAY_LOGGING_ENABLED', false),
    'device_logs_queue' => env('WAPI_GATEWAY_DEVICE_LOGS_QUEUE', 'device-logs'),

    'meta_app_id' => env('META_APP_ID'),
    'aws_bucket_url' => env('AWS_BUCKET_URL'),
];
