<?php

namespace Funnelchat\WapiGateway\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceLog extends Model
{
    public function getConnectionName()
    {
        return config('wapi-gateway.database_connection', 'mysql');
    }

    protected $table = 'device_logs';

    protected $fillable = [
        'instance_uid',
        'provider',
        'method',
        'url',
        'request_payload',
        'response_body',
        'status_code',
        'duration_ms',
        'is_error',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_body' => 'array',
        'is_error' => 'boolean',
    ];
}
