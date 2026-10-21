# demo/microservices-observability

Correlation ID y contexto de logs para microservicios Laravel.

## Qué hace

Dos middlewares que resuelven el problema de la trazabilidad entre microservicios:

| Middleware | Qué hace |
| --- | --- |
| `EnsureRequestId` | Genera un `X-Request-Id` si no viene del cliente, lo guarda en los atributos del request y lo devuelve en la respuesta. |
| `AttachLogContext` | Añade `request_id`, `service`, `ip`, `method` y `path` al contexto de todos los logs de la petición. |

## Instalación

```bash
composer require demo/microservices-observability
```

El `ServiceProvider` del paquete se autodescubre y registra los alias
`request.id` y `log.context` en el Router. La aplicación los referencia
**por nombre**, sin importar las clases concretas: así el paquete puede mover
o renombrar sus middlewares sin tocar el código de los servicios.

## Uso

```php
// bootstrap/app.php
$middleware->appendToGroup('api', 'request.id');
$middleware->appendToGroup('api', 'log.context');
```

Los alias también se pueden usar directamente en una ruta:

```php
Route::get('/foo', ...)->middleware('request.id');
```

**El orden importa**: `request.id` debe ejecutarse antes que `log.context`,
porque el segundo lee el `request_id` que el primero deja en los atributos del
request. Si se invirtiera, el contexto del log llevaría un `request_id` nulo.

El paquete **no** aplica los middlewares automáticamente: la decisión es de cada
aplicación y vive en su `bootstrap/app.php`, visible y versionado.

Si una aplicación define un alias con el mismo nombre, el suyo gana: el
provider solo registra los alias que todavía no existen.

## Resultado

```
[2026-10-20 16:22:21] local.warning: Failed login attempt
{"request_id":"77272031-ae34-4007-9297-aa3621620ea3","service":"User Service",
 "ip":"10.0.0.1","method":"POST","path":"api/tokens","email":"ana@example.com",
 "account_exists":true}
```

## Propagar el ID a otros servicios

Este paquete no propaga nada por sí solo: el cliente HTTP de cada servicio debe
reenviar la cabecera. El paquete `demo/microservices-user-service-client` ya lo hace.
