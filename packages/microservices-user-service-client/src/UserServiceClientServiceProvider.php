<?php

namespace Demo\Microservices\UserServiceClient;

use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;
use Illuminate\Support\ServiceProvider;

class UserServiceClientServiceProvider extends ServiceProvider
{
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
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/user-service-client.php' => config_path('user-service-client.php'),
            ], 'user-service-client-config');
        }
    }
}
