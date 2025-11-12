<?php

namespace Funnelchat\WapiGateway\Interfaces;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

interface WhatsAppProviderInstanceInterface
{
    public function create(Request $request): JsonResponse;
    public function subscribe(string $uid, string $token): JsonResponse;
    public function unsubscribe(string $uid, string $token): JsonResponse;
}
