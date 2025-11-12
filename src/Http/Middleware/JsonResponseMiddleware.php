<?php

namespace Funnelchat\WapiGateway\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class JsonResponseMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($response instanceof \Illuminate\Http\JsonResponse) {
            $current = $response->getData();
            $current->user_status = ['add some data here'];
            $response->setData($current);
        }

        return $response;
    }
}
