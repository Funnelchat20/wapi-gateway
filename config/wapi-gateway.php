<?php

return [
    'database_connection' => env('WAPI_GATEWAY_DATABASE_CONNECTION', 'mysql'),
    'logging_enabled' => env('WAPI_GATEWAY_LOGGING_ENABLED', false),
    'device_logs_queue' => env('WAPI_GATEWAY_DEVICE_LOGS_QUEUE', 'device-logs'),

    'meta_app_id' => env('META_APP_ID'),
    'aws_bucket_url' => env('AWS_BUCKET_URL'),

    // Bounds every read of a bucket object (Meta media uploads, template header
    // handles). Before this existed the download had no limit at all and a slow
    // S3 held the job until the lambda killed it — see Helpers\BucketFile.
    'media_download_timeout' => env('WAPI_GATEWAY_MEDIA_DOWNLOAD_TIMEOUT', 30),
    'media_download_connect_timeout' => env('WAPI_GATEWAY_MEDIA_DOWNLOAD_CONNECT_TIMEOUT', 5),
];
