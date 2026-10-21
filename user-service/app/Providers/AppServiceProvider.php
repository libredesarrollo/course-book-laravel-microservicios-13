<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerLoginRateLimiter();
    }

    /**
     * Limita POST /api/tokens, que es el endpoint que recibe contraseñas.
     *
     * Sin esto, un atacante puede hacer fuerza bruta ilimitada contra una
     * cuenta. Se aplican dos contadores porque defienden de cosas distintas:
     *
     *   - por cuenta: frena un ataque dirigido a un email concreto
     *   - por IP:      frena un barrido probando muchos emails distintos
     *
     * Laravel evalúa todos los límites de la lista y aplica el más estricto,
     * así que en cuanto uno se agota la respuesta es 429 y el resto también
     * cuentan como consumido.
     */
    private function registerLoginRateLimiter(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $decay = (int) config('tokens.throttle.decay_seconds');
            $email = mb_strtolower((string) $request->input('email'));

            return [
                // Una IP puede burnear varias cuentas, pero no una sola
                // cuenta desde muchas IP.
                Limit::perMinute((int) config('tokens.throttle.per_ip'))
                    ->by('ip:'.$request->ip()),

                // Y al revés: un atacante con muchas IP contra una sola cuenta.
                // • .'|ip:'.$request->ip(): Obtiene la dirección IP pública de quien hace la petición y la une a la llave del correo usando una barra vertical (|).
                // Al unir el correo y la IP, el sistema es muy específico. Si un atacante intenta hackear una cuenta usando muchas IPs diferentes, el límite lo bloqueará por el correo. Si intenta hackear muchas cuentas distintas desde la misma computadora, el límite lo bloqueará por la IP.

                Limit::perMinute((int) config('tokens.throttle.per_account'))
                    ->by('email:'.hash('sha256', $email).'|ip:'.$request->ip()),
            ];
        });
    }
}
