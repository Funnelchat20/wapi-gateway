<?php

namespace Funnelchat\WapiGateway\Contracts;

interface InstancesContract
{
    public function create(int $userId, int $deviceId): array;
}

