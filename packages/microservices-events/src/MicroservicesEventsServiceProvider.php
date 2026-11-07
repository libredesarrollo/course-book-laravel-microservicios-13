<?php

namespace Demo\Microservices\Events;

use Demo\Microservices\Events\Console\DispatchOutbox;
use Demo\Microservices\Events\Http\VerifyEventSignature;
use Demo\Microservices\Events\Outbox\OutboxDispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Registra el paquete: el comando del outbox, el middleware de verificación
 * y la migración del outbox (publishable).
 *
 * Las decisiones de negocio (quién se suscribe a qué, con qué secreto) viven
 * en el config/events.php de CADA aplicación: este paquete es el mecanismo,
 * no la política.
 */
class MicroservicesEventsServiceProvider extends ServiceProvider
{
    public const ALIAS_VERIFY = 'events.verify';

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/events.php',
            'events'
        );

        $this->app->singleton(OutboxDispatcher::class);

        $this->commands([DispatchOutbox::class]);
    }

    public function boot(Router $router): void
    {
        if (! array_key_exists(self::ALIAS_VERIFY, $router->getMiddleware())) {
            $router->aliasMiddleware(self::ALIAS_VERIFY, VerifyEventSignature::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'events-migrations');
        }
    }
}
