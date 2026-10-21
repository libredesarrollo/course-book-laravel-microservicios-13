# demo/microservices-user-service-client

Cliente HTTP para hablar con el `user-service` desde cualquier otro microservicio.

## Qué resuelve

Un microservicio que no tiene usuarios necesita validar tokens contra el servicio
de identidad. Este paquete encapsula esa frontera de red:

- `IdentityProvider` — el contrato (interfaz) que consume la aplicación.
- `UserServiceClient` — la implementación HTTP.
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

## Configuración

```bash
USER_SERVICE_URL=http://127.0.0.1:8000
USER_SERVICE_TIMEOUT=3
```

Para publicar el archivo de configuración en la aplicación:

```bash
php artisan vendor:publish --tag=user-service-client-config
```

## Uso

Inyecta el **contrato**, no la implementación:

```php
use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;

public function __construct(private readonly IdentityProvider $users) {}
```

```php
$user = $this->users->authenticate($token);   // array{id,name,email}|null
$token = $this->users->issueToken($credentials); // string
$this->users->revokeToken($token);              // void
```

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
