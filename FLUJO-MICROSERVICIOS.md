# Sistema de Tareas Inteligente — Cómo funciona

Demostración de **microservicios reales** con comunicación **síncrona por HTTP** entre dos
proyectos Laravel 13 independientes, cada uno con su propia base de datos.

```
┌──────────────┐        ┌──────────────────────────────────────┐        ┌──────────────┐
│              │        │                                      │        │              │
│   Cliente    │        │  task-service  (:8001)               │        │ user-service │
│   (curl /    │───────▶│  db_tasks.sqlite                    │───────▶│ (:8000)      │
│    Postman)  │ POST   │  NO tiene la tabla users            │  GET   │ db_users.    │
│              │◀───────│  valida el user_id por HTTP         │◀───────│ sqlite       │
│              │ 201    │  + UI web en /tasks                  │  200   │ (headless)   │
└──────────────┘        └──────────────────────────────────────┘  404  └──────────────┘
```

> **Cómo leer este documento**
> - Secciones **1 a 11**: lo que está **implementado y verificado** hoy.
> - Secciones **12 a 21**: **qué sigue después**. No está implementado. Es una hoja de ruta
>   con el razonamiento, para que sepas *por qué* se tomaría cada decisión.

---

# PARTE 1 — Lo que está implementado

## 1. El principio: cada servicio es dueño de sus datos

| | user-service | task-service |
|---|---|---|
| Puerto | `http://127.0.0.1:8000` | `http://127.0.0.1:8001` |
| Base de datos | `database/db_users.sqlite` | `database/db_tasks.sqlite` |
| Tablas | `users` | `tasks`, `cache`, `jobs` |
| ¿Conoce usuarios? | **Sí**, es el dueño | **No**, solo guarda el `user_id` |
| ¿Conoce tareas? | **No** | **Sí**, es el dueño |
| Interfaz | Ninguna (headless) | API REST **+ UI web Blade** |

Ninguno de los dos servicios abre la base de datos del otro. No hay JOIN, no hay llave
foránea, no hay modelo compartido. **La única forma de que el task-service sepa si un
`user_id` existe es preguntándoselo al user-service por la red.**

Eso es lo que hace que sean microservicios y no dos carpetas del mismo proyecto.

> Nota: `tasks.user_id` es un `unsignedBigInteger` **indexado pero sin llave foránea**.
> A propósito. Una FK física exigiría que ambas tablas vivieran en el mismo motor de base de
> datos, que es exactamente lo que rompe el aislamiento.

---

## 2. El flujo completo, paso a paso

1. El cliente hace `POST http://127.0.0.1:8001/api/tasks` (o envía el formulario de `/tasks/create`).
2. El **task-service** valida el payload (`title` requerido, `user_id` requerido/entero ≥ 1). Si
   falla, responde `422` sin tocar la red.
3. El **task-service** pide la lista de usuarios al **user-service** (`GET /api/users`) para
   poblar el formulario. *Solo en la UI web.*
4. Al guardar, el **task-service** abre una conexión HTTP (`Http::get`) hacia
   `http://127.0.0.1:8000/api/users/{id}`.
5. El **user-service** consulta su propia tabla `users`:
   - **200** + el modelo JSON → el usuario existe.
   - **404** + `{"error":"Usuario no encontrado"}` → el usuario no existe.
6. Según la respuesta, el task-service decide:
   - **200** → inserta la tarea en `db_tasks` y responde `201` con la tarea creada.
   - **404** → responde `422` y **no** escribe nada.
   - **Conexión rechazada / timeout** → responde `503`.
   - **Otro status (500, 502…)** → responde `502`.

Todo es **síncrono bloqueante**: el request del cliente espera a que el user-service
conteste. Es el modelo más simple de explicar; la [sección 13](#13-de-síncrono-a-asíncrono-con-rabbitmq)
explica cuándo y cómo dejar de hacerlo así.

---

## 3. user-service — el endpoint que valida

El user-service es **headless**: no tiene UI. Solo expone API.

### `routes/api.php`

```php
Route::get('/users', [UserController::class, 'index']);
Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');
```

### `app/Http/Controllers/UserController.php`

```php
public function index(): JsonResponse
{
    return response()->json(User::query()->orderBy('name')->paginate(50));
}

public function show(int $id): JsonResponse
{
    $user = User::query()->find($id);

    if (! $user) {
        return response()->json(['error' => 'Usuario no encontrado'], 404);
    }

    return response()->json($user, 200);
}
```

### Contrato

```
GET /api/users

200  {"current_page":1,"data":[{"id":1,"name":"Ana Torres","email":"..."}],"total":2, ...}

GET /api/users/{id}

200  {"id":2,"name":"Luis Ramirez","email":"luis.ramirez@example.com","created_at":"...","updated_at":"..."}
404  {"error":"Usuario no encontrado"}
```

---

## 4. task-service — API REST + UI web

### 4.1 `UserServiceClient`: toda la frontera de red en un solo lugar

Lo más importante del refactor. Antes el `Http::get` estaba pegado dentro de `store()`; con
CRUD completo habría estado copiado tres veces. Ahora vive en un cliente dedicado:

```php
namespace App\Services;

class UserServiceClient
{
    /** null = el usuario no existe (404) */
    public function find(int $id): ?array;

    /** lista para poblar el <select> del formulario */
    public function all(): array;

    private function request(string $uri): Response;  // timeout + clasificación de errores
    private function baseUrl(): string;                // config('services.user_service.url')
    private function timeout(): int;                   // config('services.user_service.timeout')
}
```

Es el patrón de un **API Gateway / service client**: encapsular la frontera de red para que el
resto de la aplicación trate al otro microservicio como si fuera una función local. Los
controllers no saben nada de URLs, timeouts ni códigos de error del peer.

### 4.2 Excepciones de dominio

```php
app/Exceptions/UserServiceUnavailableException.php   // no lo alcanzó → 503
app/Exceptions/UserServiceErrorException.php        // respondió con error → 502
```

Se renderizan **una sola vez**, en `bootstrap/app.php`, y no en cada endpoint:

```php
$exceptions->render(function (UserServiceUnavailableException $e, Request $request) {
    return $request->is('api/*') || $request->expectsJson()
        ? response()->json(['error' => 'El usuario no pudo ser validado: el user-service no está disponible.'], 503)
        : response()->view('errors.user-service-down', [...], 503);
});
```

Ningún método puede olvidarse de manejarlas.

### 4.3 API REST — `app/Http/Controllers/Api/TaskController.php`

| Método | Ruta | Respuesta |
|---|---|---|
| `index` | `GET /api/tasks` | Lista paginada (15 por página) |
| `store` | `POST /api/tasks` | `201` con la tarea |
| `show` | `GET /api/tasks/{task}` | `200` con la tarea |
| `update` | `PUT\|PATCH /api/tasks/{task}` | `200` con la tarea actualizada |
| `destroy` | `DELETE /api/tasks/{task}` | `204` |

```php
// routes/api.php
Route::apiResource('tasks', ApiTaskController::class)->names('api.tasks');
```

> **El `->names('api.tasks')` no es cosmético.** `Route::apiResource('tasks')` y
> `Route::resource('tasks')` registran los mismos nombres (`tasks.index`, `tasks.store`…). Si
> no se prefija, la API gana el registro y `route('tasks.index')` apunta a `/api/tasks`. Es un
> clásico de Laravel que marca la diferencia entre un enlace roto y un 404 misterioso.

`update` **solo revalida contra el user-service si el `user_id` cambió**. Editar un título no
gasta una llamada de red:

```php
if (isset($validated['user_id']) && $validated['user_id'] !== $task->user_id) {
    if ($userService->find($validated['user_id']) === null) {
        return response()->json(['error' => 'El usuario no existe en el sistema'], 422);
    }
}
```

### 4.4 UI web — `app/Http/Controllers/TaskController.php`

| Método | Ruta | Vista |
|---|---|---|
| `index` | `GET /tasks` | `tasks/index` (tabla + paginación) |
| `create` | `GET /tasks/create` | `tasks/create` |
| `store` | `POST /tasks` | redirect → `tasks.index` |
| `show` | `GET /tasks/{task}` | `tasks/show` |
| `edit` | `GET /tasks/{task}/edit` | `tasks/edit` |
| `update` | `PUT /tasks/{task}` | redirect → `tasks.index` |
| `destroy` | `DELETE /tasks/{task}` | redirect → `tasks.index` |

Ambos controllers usan **el mismo `UserServiceClient`**. Lo único que cambia es cómo se
renderiza el resultado: JSON en la API, redirección + error bag en la web.

Tres detalles de la UI que valen la pena:

1. **El `<select>` de usuarios se puebla por HTTP.** `create` y `edit` llaman a
   `$userService->all()`. Si el user-service está caído, el formulario **no se rompe**: cae a
   un `input type="number"` con un banner de advertencia.
2. **`show` degrada con elegancia.** Usa `rescue(fn () => $userService->find(...), report: false)`:
   si el user-service no responde, la tarea se muestra igual, solo sin el nombre del propietario.
3. **Cero paso de build.** Las vistas llevan su CSS inline en `layouts/app.blade.php` y no
   usan `@vite`. El proyecto no tiene `node_modules` ni `public/build`, así que con Vite las
   páginas habrían dado *"Unable to locate file in Vite manifest"*. La paginación también está
   escrita a mano, porque las vistas de paginación de Laravel son Tailwind.

---

## 5. Mapa de archivos

### user-service

| Archivo | Rol |
|---|---|
| `database/migrations/0001_01_01_000000_create_users_table.php` | Tabla `users`: `id`, `name`, `email`, `timestamps` |
| `app/Models/User.php` | `#[Fillable(['name','email'])]` |
| `database/factories/UserFactory.php` | Factory para tests |
| `database/seeders/DatabaseSeeder.php` | Ana Torres (id 1), Luis Ramirez (id 2) |
| `app/Http/Controllers/UserController.php` | `index()` y `show()` |
| `routes/api.php` | `GET /api/users`, `GET /api/users/{id}` |
| `bootstrap/app.php` | Registra `routes/api.php` con el prefijo `/api` |

### task-service

| Archivo | Rol |
|---|---|
| `database/migrations/2026_…_create_tasks_table.php` | `tasks`: `title`, `user_id` (sin FK), `status` |
| `app/Models/Task.php` | `STATUS_PENDING` / `STATUS_COMPLETED` |
| `app/Services/UserServiceClient.php` | **La frontera de red** |
| `app/Exceptions/UserService*.php` | 503 / 502 tipados |
| `app/Http/Requests/Store|UpdateTaskRequest.php` | Validación |
| `app/Http/Controllers/Api/TaskController.php` | API REST (JSON) |
| `app/Http/Controllers/TaskController.php` | UI web (Blade) |
| `resources/views/{layouts,tasks,errors}` | Vistas, CSS inline, página 503 |
| `config/services.php` | Bloque `user_service` (`url`, `timeout`) |
| `tests/Feature/TaskApiTest.php` | 11 tests con `Http::fake()` |
| `tests/Feature/TaskUiTest.php` | 10 tests de la UI |

---

## 6. Cómo arrancarlo

```bash
# Terminal 1
cd user-service
php artisan migrate:fresh --seed
php artisan serve --port=8000

# Terminal 2
cd task-service
php artisan migrate:fresh --seed
php artisan serve --port=8001
```

Y abrir <http://127.0.0.1:8001/tasks>.

---

## 7. Pruebas con cURL

```bash
# user-service: lista
curl -s http://127.0.0.1:8000/api/users

# user-service: usuario existe -> 200
curl -i http://127.0.0.1:8000/api/users/1

# user-service: no existe -> 404
curl -i http://127.0.0.1:8000/api/users/999

# task-service: CRUD por API
curl -s -X POST http://127.0.0.1:8001/api/tasks \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"title":"Tarea end-to-end","user_id":2}'

curl -s http://127.0.0.1:8001/api/tasks
curl -s http://127.0.0.1:8001/api/tasks/1
curl -s -X PUT http://127.0.0.1:8001/api/tasks/1 \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"title":"Renombrada","user_id":1}'
curl -s -X DELETE http://127.0.0.1:8001/api/tasks/1

# usuario inválido -> 422 y NO se guarda nada
curl -s -X POST http://127.0.0.1:8001/api/tasks \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"title":"Tarea huerfana","user_id":77}'

# user-service apagado (Ctrl+C en su terminal) -> 503
curl -s -X POST http://127.0.0.1:8001/api/tasks \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"title":"Sin user-service","user_id":1}'
```

### Postman

| # | Method | URL | Body (raw / JSON) |
|---|---|---|---|
| 1 | GET | `{{user_service}}/api/users` | — |
| 2 | GET | `{{user_service}}/api/users/1` | — |
| 3 | POST | `{{task_service}}/api/tasks` | `{"title":"Tarea","user_id":2}` |
| 4 | GET | `{{task_service}}/api/tasks` | — |
| 5 | PUT | `{{task_service}}/api/tasks/1` | `{"title":"Nueva","status":"completed"}` |
| 6 | DELETE | `{{task_service}}/api/tasks/1` | — |

```json
{ "user_service": "http://127.0.0.1:8000", "task_service": "http://127.0.0.1:8001" }
```

---

## 8. Matriz de respuestas

| Situación | user-service | task-service | ¿Se guarda? |
|---|---|---|---|
| Payload inválido | — | `422` (validación) | No |
| `user_id` existe | `200` | `201` + tarea | **Sí** |
| `user_id` no existe | `404` | `422` "El usuario no existe en el sistema" | No |
| user-service devuelve 500 | `500` | `502` | No |
| user-service apagado / timeout | — | `503` | No |

En los cuatro casos de fallo **no se escribe nada en `db_tasks`**. Ese es el invariante del flujo.

---

## 9. Tests

```bash
cd user-service && vendor/bin/pest --compact   # 5 tests
cd task-service && vendor/bin/pest --compact   # 23 tests, 68 asserts
```

Ninguno levanta el user-service: usan `Http::fake()` para simular 200, 404, 500 y conexión
caída. La suite es rápida y determinista, pero el contrato queda verificado:

```php
Http::fake(['*' => Http::response(['error' => 'Usuario no encontrado'], 404)]);

$this->postJson('/api/tasks', ['title' => 'Tarea', 'user_id' => 42])->assertStatus(422);

expect(Task::query()->count())->toBe(0);   // nada se guardó
```

---

## 10. La prueba que demuestra que es un sistema distribuido

Detén el user-service (`Ctrl+C`) y manda un `POST /api/tasks`. El task-service responde
`503` en milisegundos. Ninguna cantidad de código dentro del task-service pudo evitarlo: la
información **no está en su proceso**, está en otro proceso, detrás de otra base de datos, al
otro lado de un socket.

En la UI se ve igual de claro: el formulario de `/tasks/create` se degrada a un input numérico
con un banner, y `/tasks/{id}` sigue mostrando las tareas sin el nombre del propietario.

Y a la inversa: borra `user-service/` completo y el task-service **sigue funcionando** para
listar, editar y borrar tareas. Solo pierde la capacidad de crear tareas nuevas. Esa es la
disponibilidad parcial que da la independencia real entre servicios.

---

## 11. Limitaciones honestas de este diseño

- **Sin circuit breaker**: cada petición caída espera los 3 s completos del timeout.
- **Doble escritura sin transacción distribuida**: si el user-service responde 200 y luego
  falla el insert, la validación "pasó" pero no hay tarea.
- **Acoplamiento al contrato**: si el user-service cambia la forma del 404, el task-service se
  entera al instante.
- **Sin autenticación**: cualquiera puede crear tareas. En localhost no importa.
- **SQLite con escritura concurrent**: aguanta bien el demo, pero no es un motor de producción
  con varios workers escribiendo a la vez.

---

# PARTE 2 — Qué sigue después

> Nada de esta sección está implementado. Es una hoja de ruta con el razonamiento.

## 12. Por qué dejamos Lumen atrás

Lumen fue el framework "ligero" de Laravel para microservicios: menos dependencias, arranque
más rápido. **Laravel dejó de recomendarlo para proyectos nuevos en 2021**, y así lo dice la
documentación oficial:

> *"In the years since releasing Lumen, PHP has made a variety of wonderful performance
> improvements. For this reason, along with the availability of Laravel Octane, we no longer
> support beginning new projects with Lumen. Instead, we recommend always beginning new
> projects with Laravel."*
> — [lumen.laravel.com](https://lumen.laravel.com/)

Los números confirman el desinterés: la última versión estable es **v11.2.0 de noviembre de
2024**, y el repositorio está prácticamente archivado. Lumen solo sigue vivo powering
**Laravel Vapor**, su modo serverless.

**Las tres razones por las que Lumen perdió:**

1. **Laravel alcanzó en rendimiento.** El route caching, OPcache y la gestión de middleware
   cerraron la brecha. Lumen llegó a ser "casi igual de rápido" y bastante más incómodo.
2. **No tenía Sanctum, ni Passport, ni Blade, ni una UI decente.** Para una API de microservicio
   terminabas reinyectando a mano los componentes de Laravel… que es Lumen dejando de ser Lumen.
3. **Octane resolvió el problema que Lumen venía a resolver**, pero sin renunciar a nada.

**Consecuencia práctica para nosotros:** el argumento "es un microservicio, entonces Lumen" ya
no se sostiene. La velocidad se compra con [Octane](#15-laravel-octane), no con un framework
distinto. Si vienes de un proyecto en Lumen, el camino es: copia los archivos a un Laravel
limpio, ya que Lumen corre sobre los mismos componentes `illuminate/*` que Laravel.

---

## 13. De síncrono a asíncrono: RabbitMQ

### El problema con lo que tenemos

Hoy el `POST /tasks` se ve así en tiempo de respuesta:

```
Cliente ──20ms──▶ task-service ──20ms──▶ user-service ──20ms──▶ db_users
       ◀─────────         ◀─────────               ◀─────────
```

Cada request a task-service **suma** la latencia de user-service. Con N servicios en cadena, los
tiempos se suman. Peor: si user-service está lento pero no caído, el cliente espera igual. Y un
`POST /tasks` que conceptualmente es "guardar una tarea" termina bloqueado por "consultar un
nombre".

### Qué cambia con una cola

La validación del usuario se mueve **después** de la escritura:

```
Cliente ──5ms──▶ task-service ──▶ db_tasks ──▶ 201 Created
                                          │
                                          └──▶ RabbitMQ ──▶ worker ──▶ user-service
```

```bash
composer require vladimir-yuldashev/laravel-queue-rabbitmq
```

```dotenv
QUEUE_CONNECTION=rabbitmq
RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_QUEUE=tasks
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_WORKER=horizon
```

```php
// app/Jobs/ValidateTaskOwner.php
class ValidateTaskOwner implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60, 300];   // reintentos exponenciales
    }

    public function handle(UserServiceClient $userService): void
    {
        if ($userService->find($this->userId) !== null) {
            return;
        }

        // El usuario no existe (o ya no). La tarea queda huérfana:
        Task::query()->whereKey($this->taskId)->update(['status' => 'orphaned']);
    }
}
```

### Lo que ganas y lo que pierdes

| Ganas | Pierdes |
|---|---|
| El cliente responde en ~5 ms en vez de ~40 ms | **El invariante "si el usuario no existe, no se guarda" desaparece** |
| user-service caído ya no bloquea la escritura | Consistencia eventual: la tarea existe antes de validarse |
| Reintentos automáticos y DLQ (dead letter queue) | Hay que manejar el estado "huérfana" en la UI |
| Picos de tráfico se absorben en el broker | Depurar "se guardó pero no aparece validada" |
| Escalar el worker sin tocar el web | Idempotencia obligatoria |

**El punto importante:** la validación síncrona te da una **garantía fuerte** (nada sucio se
escribe) a cambio de latencia. La asíncrona te da **disponibilidad** a cambio de esa garantía.
Cuál elegir depende de si es aceptable que exista una tarea sin usuario válido:

- **Notificaciones, auditorías, emails** → asíncrono, sin duda. Nadie espera.
- **Integridad de datos crítica** (no facturar a un cliente inexistente) → síncrono, o un saga
  con compensación.

RabbitMQ frente a Redis como cola: Laravel trae el driver `redis` de fábrica y es más simple.
RabbitMQ gana cuando necesitas **confirmaciones de publicación, exchanges, prioridades, quorum
queues y enrutado complejo**. Si solo quieres "encolar trabajos", Redis + Horizon llega antes.

> Nota: `vladimir-yuldashev/laravel-queue-rabbitmq` soporta Octane desde la 13.3, pero hay que
> **declarar la conexión `rabbitmq` en el `warm` de `config/octane.php`**. Es el tipo de detalle
> que se olvidan y se manifiesta como conexiones abiertas por request.

---

## 14. Autenticación entre servicios: JWT, Sanctum y mTLS

Hoy `POST /api/tasks` es anónimo. En una red real, eso no es aceptable. Hay tres opciones y
**no son equivalentes** — se suelen confundir:

### Sanctum — para usuarios finales

Lo que trae Laravel de fábrica (`php artisan install:api`). Tokens **opacos** guardados en una
tabla, revocables uno por uno, con "abilities" (`tasks:create`, `tasks:delete`).

```
Authorization: Bearer 8|kQd3fR2mNx7pL...
```

Ideal para SPAs y móviles. **No** para comunicação servicio-a-servicio: el user-service tendría
que consultar la base de datos de tokens en cada request, o el token tendría que vivir más de lo
razonable.

### JWT — para validadores sin base de datos

`php-open-source-saver/jwt-auth` (v2.9.3, Laravel 12/13) es el fork mantenido de
`tymon/jwt-auth`, abandonado hace años. Un JWT es autocontenido: el user-service valida la firma
sin llamar a nadie.

**Ventajas:** stateless, horizontalmente escalable.
**Desventajas reales:**
- **Revocar es un problema.** Until `exp`, el token es válido aunque lo borres. La lista de
  revocación es una base de datos… que es justo lo que JWT iba a evitar.
- **El payload no es secreto.** Es solo base64. No pongas datos sensibles ahí.
- **Skew de reloj** entre servicios: clock drift de 30 s rompe la validación de `nbf`/`exp`.
- Si tu servicio ya tiene base de datos, **JWT no te compra nada**: la tabla de tokens es una fila
  más, y encima la tienes que mantener.

### mTLS o API Gateway — la respuesta real

En la práctica, la comunicación entre microservicios internos **no debería llevar el token de un
usuario**. Debería llevar un token de *servicio*, emitido por un API Gateway con
`client_credentials` de OAuth2: vida corta (minutos), audiencia específica, revocable.

Capa por debajo, **mTLS** (certificado por servicio) es lo más simple y lo que no se puede
robar. Con Kubernetes, mTLS se resuelve gratis con Linkerd o Istio.

**Recomendación para este proyecto:** hoy ninguno (localhost). El día que haya red: mTLS o
gateway con credenciales de servicio. JWT solo si terceros tienen que validar tokens sin poder
consultar ninguna base de datos.

---

## 15. Laravel Octane

### Qué es

`php artisan serve` arranca el servidor de desarrollo de PHP, que **bootea Laravel entero en
cada request**: lee `composer.json`, registra servicios, compila el contenedor, carga
configuración. Octane (Swoole, OpenSwoole, FrankenPHP o RoadRunner) **bootea la aplicación una
sola vez y la deja resident en memoria**, alimentándola con requests.

```bash
composer require laravel/octane
php artisan octane:install --server=frankenphp
php artisan octane:start --workers=8      # en producción
php artisan octane:reload                 # tras un deploy, recarga los workers sin downtime
```

### Ventajas reales

- **No se re-bootea Laravel por request.** Ese es el 90% de la ganancia.
- **Throughput predecible bajo carga**, porque no hay pico de CPU por request.
- **Menos memoria y GC** que PHP-FPM encenario alto.

### Cuándo **no** hacerlo

Esto es lo que casi nadie pone en los artículos: los benchmarks públicos concluyeron que **por
debajo de ~500 requests/minuto la diferencia entre PHP-FPM y Octane es imperceptible**. Una API
que recibe 50 req/min no tiene un problema de rendimiento; tiene un problema de diseño.

**Octane es una optimización para cuando ya mediste que el cuello de botella es el arranque de
PHP**, no un sustituto de medir.

### Los riesgos (y son serios)

1. **El estado vive entre requests.** Una variable estática, un `static::$cache` o un singleton
   con estado se **comparte entre los usuarios** del worker. Es la fuente de bugs más común al
   migrar: "funcionaba en `artisan serve` y en producción se filtran datos entre peticiones".
2. **El container se reinicializa.** Los singletons que guarden estado se quedan viejos.
3. **Los logs se acumulan en memoria** si no se rotan.
4. **`php artisan serve` sigue siendo el servidor de desarrollo.** Octane nunca reemplaza a
   Valet/Herd ni a tu `.env` local.

En nuestro contexto: user-service recibe 1 request por cada `POST /tasks` más 1 por cada
`GET /tasks/create`. **No justifica Octane todavía.** Lo justificaría si el `index()` lo
convirtiéramos en una vista que golpea user-service N veces.

---

## 16. Observabilidad: que el error se vea solo

Hoy, si el task-service tarda 2 segundos, nadie sabe por qué. Falta lo que en el mundo real se
llama *distributed tracing*:

- **Correlación de requests.** Generar un ID en el borde y propagarlo:
  `X-Request-Id: 8f2c…` en cada respuesta y en cada llamada saliente. Con eso, un `grep` en los
  logs de los dos servicios te arma la historia completa.
- **OpenTelemetry** (o el paquete de Laravel que lo envuelve) para trazas distribuidas: una
  petición del cliente se convierte en un árbol de spans que cruza el límite de los servicios.
- **Logs estructurados** en JSON en vez de texto, con `user_id`, `task_id`, `status`, `duration`.
- **Métricas**: p95 de latencia, tasa de error por endpoint, y *cuántas veces* cae el
  user-service. Esa última métrica es la que te avisa de que necesitas el circuit breaker.

```php
// app/Services/UserServiceClient.php
$response = Http::withHeaders(['X-Request-Id' => request()->attributes->get('request_id')])
    ->timeout($this->timeout())
    ->get($this->baseUrl().$uri);
```

---

## 17. Resiliencia: circuit breaker e idempotencia

### Circuit breaker

Hoy, con el user-service caído, cada `POST /tasks` **espera 3 segundos** antes de rendirse. Con
50 usuarios, son 50 hilos bloqueados 3 segundos cada uno. El servicio caído se convierte en una
trampa que degrada al servicio sano.

Un circuit breaker memoriza el fallo: tras N errores seguidos **deja de llamar** y falla
inmediatamente durante M segundos. Pasa de 3 s a 3 ms.

```php
// pseudo-código dentro de UserServiceClient
if ($this->breaker->isOpen()) {
    throw new UserServiceUnavailableException('Circuito abierto, sin intentar la llamada.');
}
```

### Idempotencia

`POST /tasks` no lo es: si el cliente no recibe la respuesta y reintenta, se crea una tarea
duplicada. Con colas esto es obligatorio, no opcional.

```http
Idempotency-Key: 7c9e6679-7425-40de-944b-e07fc1f90ae7
```

Guardas el `Idempotency-Key` en una tabla con la respuesta. Si vuelve la misma clave, devuelves
la respuesta guardada en lugar de reejecutar.

---

## 18. Testing de contratos

Hoy los tests de cada servicio mockean al otro con `Http::fake()`. Funciona hasta el día en que
alguien cambia el user-service y olvida actualizar el mock del task-service: los tests siguen
en verde y producción se rompe.

Los **contratos del consumidor** (Pest los soporta) invierten eso: el user-service genera un
test a partir de lo que el task-service *realmente* consume, y ambos servicios verifican contra
ese archivo compartido en CI.

```
contracts/user-service.json   ← generado, versionado, revisado en code review
```

El día que cambies la forma del 404, el contrato falla en CI, en el repositorio del
user-service, y el cambio no se puede mergear a medias.

---

## 19. Despliegue en contenedores

Cada servicio en su propio contenedor, con su propia base de datos. Un `docker-compose.yml` con:

```yaml
services:
  user-service:  { ports: ["8000:8000"], env_file: .env }
  task-service:  { ports: ["8001:8001"], env_file: .env, depends_on: [user-service] }
```

Tres cosas que hay que cuidar y que aprendes tarde:

1. **El orden de arranque no es el orden de disponibilidad.** `depends_on` solo espera el
   contenedor, no la migración. Por eso existen los **health checks**:
   ```yaml
   healthcheck:
     test: ["CMD", "php", "artisan", "db:show"]     # o /up
     interval: 10s
     retries: 5
   ```
2. **`USER_SERVICE_URL` no puede ser `localhost`** dentro de un contenedor: ahí solo está el
   propio contenedor. Tiene que ser el nombre del servicio o del DNS interno.
3. **Un contenedor no debe escribir en el sistema de archivos.** `storage/framework/{cache,sessions,views}`
   deben ser volúmenes compartidos, o `config:cache` en cada deploy y no cachear rutas nunca.

---

## 20. Roadmap sugerido (en orden)

| # | Paso | Por qué en este orden |
|---|---|---|
| 1 | **Métricas y `X-Request-Id`** | Antes de optimizar nada, saber qué pasa |
| 2 | **Autenticación** (mTLS o token de servicio) | Es lo que impide que cualquiera cree tareas |
| 3 | **Circuit breaker + reintentos** | Convierte una caída de 3 s en una de 3 ms |
| 4 | **API versioning** (`/api/v1/...`) | Barato ahora, carísimo después |
| 5 | **Contratos de consumidor en CI** | Antes de que haya dos equipos |
| 6 | **RabbitMQ** | Solo si el punto 3 muestra que la latencia secuencial duele |
| 7 | **Docker + health checks** | Cuando el proyecto se compare con otros |
| 8 | **Octane** | Solo después de medir que el arranque es el cuello de botella |
| 9 | **JWT** | Probablemente nunca, si usas mTLS |

El orden importa: los pasos 1 a 3 cuestan horas y evitan incidentes. El paso 8 puede esperar un
año sin que nadie lo note.

---

*user-service `:8000` · db_users.sqlite | task-service `:8001` · db_tasks.sqlite*