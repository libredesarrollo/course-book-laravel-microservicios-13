<?php

namespace Demo\Microservices\Observability;

use Demo\Microservices\Observability\Http\Middleware\AttachLogContext;
use Demo\Microservices\Observability\Http\Middleware\EnsureRequestId;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Registra la API pública del paquete: los alias de middleware.
 *
 * La aplicación que consume el paquete referencia 'request.id' y 'log.context'
 * por nombre, sin importar las clases concretas. Así el paquete puede mover o
 * renombrar sus middlewares sin tocar el código de los servicios.
 */
class MicroservicesObservabilityServiceProvider extends ServiceProvider
{
    public const ALIAS_REQUEST_ID = 'request.id';

    public const ALIAS_LOG_CONTEXT = 'log.context';

    /**
     * Alias que el paquete registra y los middlewares a los que apuntan.
     *
     * @var array<string, class-string>
     */
    public const MIDDLEWARE_ALIASES = [
        self::ALIAS_REQUEST_ID => EnsureRequestId::class,
        self::ALIAS_LOG_CONTEXT => AttachLogContext::class,
    ];

    public function boot(Router $router): void
    {
        foreach (self::MIDDLEWARE_ALIASES as $alias => $middleware) {
            if (! array_key_exists($alias, $router->getMiddleware())) {
                $router->aliasMiddleware($alias, $middleware);
            }
        }
    }
}
