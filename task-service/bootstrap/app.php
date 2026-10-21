<?php

use App\Exceptions\UserServiceErrorException;
use App\Exceptions\UserServiceUnavailableException;
use App\Http\Middleware\AttachLogContext;
use App\Http\Middleware\AuthenticateWithUserService;
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
        $middleware->appendToGroup('web', EnsureRequestId::class);
        $middleware->appendToGroup('web', AttachLogContext::class);
        $middleware->appendToGroup('api', EnsureRequestId::class);
        $middleware->appendToGroup('api', AttachLogContext::class);

        $middleware->alias([
            'auth.user-service' => AuthenticateWithUserService::class,
            'request.id' => EnsureRequestId::class,
            'log.context' => AttachLogContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * El user-service es una dependencia externa: si cae, el task-service
         * no puede validar usuarios. Estas dos reglas traducen el fallo
         * de red a una respuesta útil: JSON 503/502 en la API y una página
         * 503 en la UI. Se registran aquí y no en cada controller para que
         * ningún endpoint pueda olvidarse de manejarlas.
         */
        $exceptions->render(function (UserServiceUnavailableException $exception, Request $request) {
            return $request->is('api/*') || $request->expectsJson()
                ? response()->json(['error' => 'El usuario no pudo ser validado: el user-service no está disponible.'], 503)
                : response()->view('errors.user-service-down', [
                    'message' => 'El user-service no está disponible en http://'.config('services.user_service.url').'. Arráncalo con: php artisan serve --port=8000',
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
