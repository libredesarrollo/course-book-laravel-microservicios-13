<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id');

        if (! is_string($requestId) || $requestId === '') {
            $requestId = (string) Str::uuid();
        }

        // Attach to request for easy access in logs/controllers
        $request->attributes->set('request_id', $requestId);

        /** @var Response $response */
        $response = $next($request);

        // Propagate back to caller
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
