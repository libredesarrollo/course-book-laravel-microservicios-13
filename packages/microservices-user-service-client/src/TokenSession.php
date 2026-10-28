<?php

namespace Demo\Microservices\UserServiceClient;

use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;
use Illuminate\Http\Request;
use Throwable;

/**
 * Sesión web respaldada por un token remoto.
 *
 * El token no lo genera la aplicación: lo emite el user-service y la
 * aplicación solo lo guarda en la sesión. Este servicio se encarga del
 * ciclo de vida completo, que es la parte sutil: al iniciar sesión hay que
 * regenerar el identificador de sesión (para que un token de sesión fijado
 * no valga) y al cerrarla hay que revocar el token en el peer (si solo se
 * borra de la sesión, el token sigue vivo en la tabla del user-service).
 *
 * Es reutilizable por cualquier consumidor del cliente HTTP: la aplicación
 * conserva el control de las vistas y de las redirecciones.
 */
class TokenSession
{
    /**
     * Clave de la sesión donde se guarda el token.
     */
    public const SESSION_KEY = 'api_token';

    public function __construct(private readonly IdentityProvider $users) {}

    /**
     * Pide un token al user-service y lo guarda en la sesión.
     *
     * @param  array{email: string, password: string}  $credentials
     *
     * @throws UserServiceUnavailableException si el user-service no está alcanzable
     * @throws UserServiceErrorException si las credenciales son incorrectas
     */
    public function login(Request $request, array $credentials, string $deviceName): string
    {
        $token = $this->users->issueToken($credentials + ['device_name' => $deviceName]);

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $token);

        return $token;
    }

    /**
     * Guarda un token ya emitido por el IdP en la sesión.
     *
     * Úsalo en el hand-off SSO: el token llega del user-service tras
     * intercambiar el código, no se pide con credenciales.
     */
    public function loginWithToken(Request $request, string $token): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $token);
    }

    /**
     * Revoca el token en el user-service y destruye la sesión local.
     *
     * Revocar en el peer es lo que lo hace inutilizable de verdad: si solo
     * lo borramos de la sesión, el token sigue vivo en su tabla. Si la
     * revocación falla (el peer está caído, por ejemplo) la sesión local se
     * destruye igualmente: el usuario queda fuera de la aplicación, y el
     * token huérfano caduca por su cuenta.
     */
    public function logout(Request $request): void
    {
        $token = $request->session()->get(self::SESSION_KEY);

        if (is_string($token) && $token !== '') {
            try {
                $this->users->revokeToken($token);
            } catch (Throwable) {
                // La sesión local se destruye igualmente: revocar es mejor
                // esfuerzo, no condición para cerrar sesión. Si el peer está
                // caído, el token queda huérfano y caduca por su cuenta.
            }
        }

        // invalidate() vacía la sesión y genera un id nuevo, así que el token
        // desaparece de aquí. regenerateToken() repone el token CSRF, que el
        // vaciado se llevó por delante.
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
