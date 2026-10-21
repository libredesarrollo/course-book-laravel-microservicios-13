<?php

namespace Demo\Microservices\UserServiceClient;

use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;
use Demo\Microservices\UserServiceClient\Http\Middleware\AuthenticateWithUserService;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Enlaza el paquete con la aplicación.
 *
 * Hace tres cosas: publica la configuración, registra las implementaciones
 * en el contenedor y expone el middleware bajo un alias para que las
 * aplicaciones no tengan que importar ninguna clase del paquete.
 */
class UserServiceClientServiceProvider extends ServiceProvider
{
    /**
     * Alias con el que la aplicación referencia el middleware del paquete.
     */
    public const ALIAS_AUTHENTICATE = 'auth.user-service';

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/user-service-client.php',
            'user-service-client'
        );

        $this->app->singleton(UserServiceClient::class);

        // El contrato es lo que consumen la aplicación; la implementación
        // concreta es un detalle que puede cambiar sin tocar a nadie.
        // Cuando se pida la interfaz, entrega la implementación de UserServiceClient
        $this->app->bind(IdentityProvider::class, UserServiceClient::class);

        // La sesión con token remoto comparte el cliente, así que también
        // puede ser un singleton: no guarda estado entre peticiones.
        $this->app->singleton(TokenSession::class);
    }

    public function boot(Router $router): void
    {
        // Solo se registra si la aplicación no ha declarado el alias con
        // otro nombre. Así el paquete ofrece un valor por defecto y la
        // aplicación conserva la última palabra.
        if (! array_key_exists(self::ALIAS_AUTHENTICATE, $router->getMiddleware())) {
            $router->aliasMiddleware(self::ALIAS_AUTHENTICATE, AuthenticateWithUserService::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/user-service-client.php' => config_path('user-service-client.php'),
            ], 'user-service-client-config');
        }
    }
}
