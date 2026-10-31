<?php

use Demo\Microservices\UserServiceClient\Exceptions\UserServiceErrorException;
use Demo\Microservices\UserServiceClient\Exceptions\UserServiceUnavailableException;
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
        // 'request.id' y 'log.context' los registra el paquete de observabilidad.
        $middleware->appendToGroup('web', 'request.id');
        $middleware->appendToGroup('web', 'log.context');
        $middleware->appendToGroup('api', 'request.id');
        $middleware->appendToGroup('api', 'log.context');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * El user-service es una dependencia externa: si cae, billing no
         * puede validar usuarios. Igual que en task-service, el fallo de red
         * se traduce a 503/502 útiles en vez de un 500 críptico.
         */
        $exceptions->render(function (UserServiceUnavailableException $exception, Request $request) {
            return $request->is('api/*') || $request->expectsJson()
                ? response()->json(['error' => 'El usuario no pudo ser validado: el user-service no está disponible.'], 503)
                : response()->view('errors.user-service-down', [
                    'message' => 'El user-service no está disponible en '.config('user-service-client.url').'. Arráncalo con: php artisan serve --port=8000',
                ], 503);
        });

        $exceptions->render(function (UserServiceErrorException $exception, Request $request) {
            return $request->is('api/*') || $request->expectsJson()
                ? response()->json(['error' => 'El usuario no es válido: el user-service respondió con error.'], 502)
                : response()->view('errors.user-service-down', [
                    'message' => 'El user-service respondió con un error inesperado. Revisa sus logs.',
                ], 502);
        });
    })->create();
