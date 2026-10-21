<?php

use App\Http\Middleware\AttachLogContext;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureRequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', EnsureRequestId::class);
        $middleware->appendToGroup('api', AttachLogContext::class);

        $middleware->alias([
            'auth.api-token' => AuthenticateApiToken::class,
            'request.id' => EnsureRequestId::class,
            'log.context' => AttachLogContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
