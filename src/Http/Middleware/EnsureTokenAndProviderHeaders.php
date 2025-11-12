<?php

namespace Funnelchat\WapiGateway\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTokenAndProviderHeaders
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->hasHeader('token')) {
            return response()->json(['error' => 'Token header is missing'], 400);
        }

        return $next($request);
    }
}
