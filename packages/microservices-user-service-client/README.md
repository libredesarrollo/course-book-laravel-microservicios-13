# demo/microservices-user-service-client

Cliente HTTP para hablar con el `user-service` desde cualquier otro microservicio.

## Qué resuelve

Un microservicio que no tiene usuarios necesita validar tokens contra el servicio
de identidad. Este paquete encapsula esa frontera de red **completa**: el cliente
HTTP, el contrato, el middleware que la engancha al ciclo de vida de Laravel y el
servicio de sesión con token remoto.

- `IdentityProvider` — el contrato (interfaz) que consume la aplicación.
- `UserServiceClient` — la implementación HTTP.
- `AuthenticateWithUserService` — el middleware que autentica preguntando al peer.
- `AuthenticatedUser` — la identidad que devuelve el peer, ya tipada.
- `TokenSession` — el ciclo de vida de la sesión web respaldada por un token.
- `UserServiceUnavailableException` — el servicio no respondió (caída de red).
- `UserServiceErrorException` — el servicio respondió con error.

La distinción entre las dos excepciones es la que permite responder `503` a una
caída de infraestructura y `502` a un contrato roto, en lugar de un `500` genérico
para todo.

## Instalación

```bash
composer require demo/microservices-user-service-client
```

El ServiceProvider se descubre solo y registra:

- `UserServiceClient` como singleton.
- `IdentityProvider` enlazado a `UserServiceClient`.
- `TokenSession` como singleton.
- El alias de middleware `auth.user-service`.

## Configuración

```bash
USER_SERVICE_URL=http://127.0.0.1:8000
USER_SERVICE_TIMEOUT=3
USER_SERVICE_CLIENT=mi-app
# Opcional: si no se define, se usa url('/sso/callback') de la app.
USER_SERVICE_CALLBACK_URL=
# Opcional: nombre de la ruta que arranca el flujo SSO (por defecto sso.start).
USER_SERVICE_SSO_START_ROUTE=sso.start
```

Para publicar el archivo de configuración en la aplicación:

```bash
php artisan vendor:publish --tag=user-service-client-config
```

`client` es el identificador con el que la aplicación se presenta ante el IdP
(`task`, `billing`...). El IdP lo valida contra su whitelist y lo usa para saber
a qué callback puede redirigir.

`sso_start_route` es la ruta local que arranca el flujo SSO. El middleware manda
ahí a los visitantes web sin sesión; si se deja vacía, responde siempre `401` en
JSON, sin redirigir, que es lo que necesita una aplicación que solo expone API.

## Uso

### Autenticar

El middleware se referencia por alias, sin importar ninguna clase del paquete:

```php
// routes/web.php
Route::middleware('auth.user-service')->group(function () {
    // ...
});
```

Si la aplicación necesita otro nombre de alias, puede registrar el suyo y el del
paquete no lo pisa: el paquete solo registra el alias si todavía no existe.

El middleware deja la identidad en los atributos del request. Para leerla:

```php
use Demo\Microservices\UserServiceClient\AuthenticatedUser;

$user = AuthenticatedUser::fromRequest(request());
```

### SSO con el IdP central

El paquete trae los tres controllers del flujo. La aplicación solo declara las
rutas:

```php
use Demo\Microservices\UserServiceClient\Http\Controllers\SsoCallbackController;
use Demo\Microservices\UserServiceClient\Http\Controllers\SsoLogoutController;
use Demo\Microservices\UserServiceClient\Http\Controllers\SsoStartController;

Route::get('/sso/start', SsoStartController::class)->name('sso.start');
Route::get('/sso/callback', SsoCallbackController::class)->name('sso.callback');
Route::post('/logout', SsoLogoutController::class)->name('logout');
```

- `SsoStartController` redirige al `/authorize` del IdP con `client` y
  `redirect_uri`.
- `SsoCallbackController` canjea el `code` por un token y lo guarda en sesión.
- `SsoLogoutController` destruye la sesión local y delega el cierre en el IdP
  (`/logout`), que es el dueño de la sesión SSO.

Ningún RP tiene pantalla de login ni tabla de usuarios: el único que conoce
credenciales es el IdP.

### Sesión web con token remoto

`TokenSession` se encarga de la parte sutil: regenerar el identificador de
sesión al guardar el token y revocarlo en el peer al cerrar sesión.

```php
use Demo\Microservices\UserServiceClient\TokenSession;

$session->loginWithToken($request, $token); // tras el canje SSO
$session->logout($request);                 // revoca el token y vacía la sesión
```

La aplicación conserva el control de las vistas y de las redirecciones: el
paquete no renderiza nada.

### Cliente directo

Si necesitas el cliente sin el middleware, inyecta el **contrato**, no la
implementación:

```php
use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;

public function __construct(private readonly IdentityProvider $users) {}
```

```php
$user = $this->users->authenticate($token);   // array{id,name,email}|null
$token = $this->users->issueToken($credentials); // string
$result = $this->users->exchangeCode($code, 'mi-app'); // array|null
$this->users->revokeToken($token);              // void
```

`exchangeCode()` devuelve `null` cuando el código es inválido, expiró, ya se usó
o pertenece a otro cliente. Igual que `authenticate()`, eso no es un fallo de
infraestructura: es un código que no sirve.

`authenticate()` devuelve `null` cuando el token no vale (401). Eso **no** es un
error: es la respuesta normal a un token caducado o revocado.

## Trazabilidad

Todas las peticiones reenvían el `X-Request-Id` de la petición entrante, para que la
traza cruce los dos servicios. Funciona junto con `demo/microservices-observability`.

## Tests

El cliente no necesita el otro servicio para probarse: `Http::fake()` basta.

```php
Http::fake(['*/api/user' => Http::response(['id' => 1, 'name' => 'Ana', 'email' => 'ana@example.com'])]);
```

## Formateo

El paquete se formatea con el preset de Laravel. No lleva su propio `pint.json`
para no duplicar la configuración, así que se formatea desde el servicio que lo
consume:

```bash
cd task-service && vendor/bin/pint ../packages
```
