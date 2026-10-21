<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AttachLogContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->attributes->get('request_id');

        if (! is_string($requestId) || $requestId === '') {
            $requestId = $request->header('X-Request-Id');
        }

        if (! is_string($requestId) || $requestId === '') {
            $requestId = null;
        }
        
        Log::withContext([
            'request_id' => $requestId,
            'service' => config('app.name'),
            'ip' => $request->ip(),
            'method' => $request->getMethod(),
            'path' => $request->path(),
        ]);

        return $next($request);
    }
}
