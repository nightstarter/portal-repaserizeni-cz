<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePohodaBridgeEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('integrations.pohoda_bridge.enabled')) {
            return new JsonResponse(['error' => ['code' => 'BRIDGE_UNAVAILABLE']], 503);
        }

        return $next($request);
    }
}
