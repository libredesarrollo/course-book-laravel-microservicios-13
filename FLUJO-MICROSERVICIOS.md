# Sistema de Tareas Inteligente — Cómo funciona

Demostración de **microservicios reales** con comunicación **síncrona por HTTP** entre dos
proyectos Laravel 13 independientes, cada uno con su propia base de datos, y **autenticados**
por tokens opacos que el task-service valida consultando al user-service en cada request.

```
┌─────────────┐     ┌────────────────────────────────┐     ┌──────────────────────────┐
│             │     │                                │     │                          │
│  Cliente    │────▶│  task-service   :8001          │────▶│  user-service  :8000    │
│  curl /     │     │  db_tasks.sqlite               │ GET │  db_users.sqlite         │
│  Postman/UI │◀────│  API REST + UI web             │◀────│  /api/tokens  /api/user  │
│             │201  │  NO tiene tabla users          │200  │  (identidad)  headless   │
└─────────────┘     └────────────────────────────────┘404  └──────────────────────────┘
```

Dos hechos que hacen que esto sea un sistema distribuido y no dos carpetas:

1. **Ninguno abre la base de datos del otro.** No hay JOIN, no hay llave foránea cruzada.
2. **Ninguno puede saber quién es el usuario por su cuenta.** El task-service no tiene usuarios,
   así que le pregunta al user-service por HTTP en cada request. Si ese servicio cae, el otro
   deja de poder autenticar a nadie.

> **Cómo leer este documento**
> - Secciones **1 a 12**: lo que está **implementado y verificado** hoy.
> - Secciones **13 a 21**: **qué sigue después**. No está implementado. Es una hoja de ruta
>   con el razonamiento, para que sepas *por qué* se tomaría cada decisión.

---

# PARTE 1 — Lo que está implementado

## 1. El principio: cada servicio es dueño de sus datos

| | user-service | task-service |
|---|---|---|
| Puerto | `http://127.0.0.1:8000` | `http://127.0.0.1:8001` |
| Base de datos | `database/db_users.sqlite` | `database/db_tasks.sqlite` |
| Tablas | `users`, `api_tokens` | `tasks`, `cache`, `jobs` |
| ¿Conoce usuarios? | **Sí**, es el dueño | **No**, solo guarda el `user_id` |
| ¿Conoce tareas? | **No** | **Sí**, es el dueño |
| Rol | Identidad: emite y valida tokens | Recursos: tareas del usuario autenticado |
| Interfaz | Ninguna (headless) | API REST **+ UI web Blade** |
| Tests | 16 | 34 (88 asserts) |

> Nota: `tasks.user_id` es un `unsignedBigInteger` **indexado pero sin llave foránea**. A
> propósito: una FK física exigiría que ambas tablas vivieran en el mismo motor de base de
> datos, que es exactamente lo que rompe el aislamiento.
>
> En cambio `api_tokens.user_id` **sí** tiene FK, porque es una integridad interna del
> user-service con su propia tabla. Lo que no existe son FKs cruzadas.

---

## 2. El flujo completo, paso a paso

### 2.1 Login (una vez)

1. El cliente manda email y contraseña a `POST /api/tokens` del **user-service**.
2. El user-service verifica el hash de la contraseña y emite un **token opaco**: 64 caracteres
   hexadecimales aleatorios. En su tabla guarda solo el SHA-256.
3. Devuelve `201` con el token **en claro**. Es la única vez que existe en esa forma.

### 2.2 Cada request posterior

1. El cliente envía `Authorization: Bearer <token>` (API) o el task-service lo lee de su sesión (web).
2. El middleware `AuthenticateWithUserService` **reenvía el token al user-service** por HTTP:
   `GET /api/user`.
3. El user-service busca por hash y responde `200 {id, name, email}`, o `401` si no vale.
4. El task-service guarda esa identidad en la petición y, si corresponde, continúa.
5. El `user_id` de la tarea sale **de la identidad**, nunca del cuerpo del request.
6. Para una tarea concreta, `findOwnedTask()` comprueba que el `user_id` guardado sea el del
   usuario en sesión. Si no, `403`.

### 2.3 El detalle que cambia todo

**Ya no existe la validación explícita de `user_id`.** Antes el controller llamaba al
user-service para preguntar "¿este id corresponde a alguien?". Ahora esa pregunta ya la
contestó el middleware, con el mismo HTTP, en el mismo request. Preguntarlo otra vez sería
pagar la latencia otra vez para obtener la misma respuesta.

Es la diferencia entre *validar que un dato existe* y *autenticar quién eres*. La segunda
absorbe a la primera, y además responde a una pregunta que la primera nunca hizo.

---

## 3. user-service — identidad

### Rutas

```php
// Público
Route::post('/tokens', [AuthController::class, 'store']);       // login

// Protegido con token de API
Route::middleware('auth.api-token')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);         // introspección
    Route::delete('/tokens', [AuthController::class, 'destroy']);  // revocar
});

// Catálogo (abierto a propósito: la UI no lo consume, pero es API pública del servicio)
Route::get('/users', [UserController::class, 'index']);
Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');
```

### `GET /api/user` — el endpoint clave

Es el que el task-service consulta en cada request. Su equivalente en un proveedor de identidad
real es la **introspección de tokens**: el recurso protegido no guarda usuarios, reenvía el
token y pregunta.

```
GET /api/user
Authorization: Bearer 64-char-token

200  {"id":1,"name":"Ana Torres","email":"ana.torres@example.com"}
401  {"error":"Unauthenticated.","message":"Falta un token válido..."}
```

### Tabla `api_tokens`

```php
Schema::create('api_tokens', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();

    // Solo se guarda el hash. El token en claro existe una sola vez,
    // en la respuesta del POST /api/tokens.
    $table->string('token_hash', 64)->unique();

    $table->string('name')->default('api-token');
    $table->timestamp('last_used_at')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();
});
```

**Por qué SHA-256 y no bcrypt/Argon2:** el token no es una contraseña que alguien pueda
escoger, son 32 bytes de `random_bytes`. No hay espacio de búsqueda que recorrer, así que el
atacante tendría que adivinar el token entero. El hash está ahí para que una fuga de la base de
datos no sirva. Con una contraseña elegida por el usuario el ataque de fuerza bruta sí es
realista, y ahí sí toca Argon2.

### Otras decisiones del login

- **Mismo mensaje y mismo status** para "usuario inexistente" y "contraseña incorrecta". Si no,
  la respuesta sirve para enumerar qué emails están dados de alta.
- `last_used_at` se actualiza en cada request, para poder listar sesiones activas.
- El logout **revoca la fila**. Si solo se borrara la cookie de sesión, el token seguiría
  sirviendo desde curl hasta que expirara. Eso es un fallo de seguridad, no una molestia.

---

## 4. task-service — API REST + UI web

### 4.1 `UserServiceClient`: toda la frontera de red

```php
class UserServiceClient
{
    /** null si el token no es válido */
    public function authenticate(string $token): ?array;      // GET  /api/user

    public function issueToken(array $credentials): string;  // POST /api/tokens

    public function revokeToken(string $token): void;        // DELETE /api/tokens

    private function request(string $uri, array $headers = []): Response;
    private function post(string $uri, array $payload = [], array $headers = []): Response;
    private function delete(string $uri, array $headers = []): Response;
    private function send(callable $callback): Response;     // ConnectionException → excepción
}
```

Es el patrón de un **API Gateway / service client**: encapsular la frontera de red para que el
resto de la aplicación trate al otro microservicio como si fuera una función local.

La distinción que importa: **`401` no es un fallo del servicio**, es "este token no vale" y se
traduce a `null`. Cualquier otro status es un problema del peer y se lanza excepción. Confundir
los dos convierte un "vuelve a iniciar sesión" en un "el sistema está roto".

### 4.2 `AuthenticateWithUserService`: el middleware

```php
public function handle(Request $request, Closure $next): Response
{
    $token = $request->bearerToken()
        ?? ($request->hasSession() ? $request->session()->get('api_token') : null);

    if (! is_string($token) || $token === '') {
        return $this->reject($request);
    }

    $user = $this->users->authenticate($token);

    if ($user === null) {
        if ($request->hasSession()) {
            $request->session()->forget('api_token');
        }

        return $this->reject($request);
    }

    $request->attributes->set('authenticated_user', AuthenticatedUser::fromArray($user));

    return $next($request);
}
```

Una sola pieza decide `401` en JSON para la API y redirección a `/login` para la UI. Ningún
controller tiene que acordarse.

### 4.3 Identidad y propiedad

```php
// app/Http/Controllers/Controller.php
protected function user(): AuthenticatedUser          // la identidad resuelta
protected function owns(int $userId): bool
protected function findOwnedTask(int $taskId): Task   // findOrFail + abort_unless(owns, 403)
```

`findOwnedTask()` centraliza la comprobación de propiedad en un sitio, en vez de repetirla en
cinco métodos. Y el modelo se resuelve **dentro** del controller, no con route model binding:

> `SubstituteBindings` corre **antes** del middleware de autenticación. Con binding implícito,
> un anónimo recibía `404` para "esta tarea no existe" y `403` para "existe pero no es tuya":
> dos respuestas distintas para dos datos que no debería conocer. Resolviéndolo después de
> autenticar, un intruso solo ve siempre `401`.

### 4.4 Rutas

```php
// routes/api.php — todo privado
Route::middleware('auth.user-service')->group(function () {
    Route::apiResource('tasks', ApiTaskController::class)->names('api.tasks');
});

// routes/web.php
Route::get('/login',  [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:6,1');
Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

Route::middleware('auth.user-service')->group(function () {
    Route::resource('tasks', TaskController::class);
});
```

> El `->names('api.tasks')` no es cosmético: `Route::apiResource` y `Route::resource` registran
> los mismos nombres. Sin el prefijo, la API gana el registro y `route('tasks.index')` termina
> apuntando a `/api/tasks`. Es un clásico de Laravel.

### 4.5 API REST

| Método | Ruta | Respuesta |
|---|---|---|
| `index` | `GET /api/tasks` | Lista paginada **solo del caller** |
| `store` | `POST /api/tasks` | `201` — `user_id` sale de la identidad |
| `show` | `GET /api/tasks/{id}` | `200` · `403` si no es tuya |
| `update` | `PUT\|PATCH /api/tasks/{id}` | `200` · `403` |
| `destroy` | `DELETE /api/tasks/{id}` | `204` · `403` |

### 4.6 UI web

| Método | Ruta | Vista |
|---|---|---|
| `index` | `GET /tasks` | `tasks/index` (tabla + paginación) |
| `create` | `GET /tasks/create` | `tasks/create` |
| `store` | `POST /tasks` | redirect → `tasks.index` |
| `show` | `GET /tasks/{id}` | `tasks/show` |
| `edit` | `GET /tasks/{id}/edit` | `tasks/edit` |
| `update` | `PUT /tasks/{id}` | redirect → `tasks.index` |
| `destroy` | `DELETE /tasks/{id}` | redirect → `tasks.index` |

Ambos controllers usan **el mismo `UserServiceClient`**. Lo único que cambia es el renderizado.

La UI guarda el token del user-service en su propia sesión de Laravel y el middleware lo lee de
ahí. **El task-service nunca ve una contraseña**: solo la reenvía y guarda lo que le devuelven.

El logout revoca el token en el user-service, no solo borra la sesión local.

**Ya no hay selector de usuario en el formulario.** Con autenticación, la tarea es de quien la
creó; permitir asignarla a otro sería reintroducir el problema que la auth resolvió.

---

## 5. Mapa de archivos

### user-service

| Archivo | Rol |
|---|---|
| `database/migrations/…_create_users_table.php` | `users`: `id`, `name`, `email`, `timestamps` |
| `database/migrations/…_add_password_to_users_table.php` | Añade `password` |
| `database/migrations/…_create_api_tokens_table.php` | `api_tokens` |
| `app/Models/User.php` | `tokens()`, `passwordMatches()`, `#[Hidden(['password'])]` |
| `app/Models/ApiToken.php` | `issueFor()`, `findByPlainText()` |
| `app/Http/Controllers/AuthController.php` | `store()`, `me()`, `destroy()` |
| `app/Http/Controllers/UserController.php` | `index()`, `show()` |
| `app/Http/Middleware/AuthenticateApiToken.php` | Valida contra la tabla de **este** servicio |
| `routes/api.php` | `POST /tokens`, `GET /user`, `DELETE /tokens`, `GET /users` |
| `tests/Feature/AuthApiTest.php` | 11 tests |
| `tests/Feature/UserControllerTest.php` | 5 tests |

### task-service

| Archivo | Rol |
|---|---|
| `app/Services/UserServiceClient.php` | **La frontera de red** |
| `app/Http/Middleware/AuthenticateWithUserService.php` | Pregunta al user-service por HTTP |
| `app/Support/AuthenticatedUser.php` | DTO de identidad (solo request) |
| `app/Http/Controllers/AuthController.php` | Login/logout de la UI |
| `app/Http/Controllers/TaskController.php` | UI web (Blade) |
| `app/Http/Controllers/Api/TaskController.php` | API REST (JSON) |
| `app/Http/Controllers/Controller.php` | `user()`, `owns()`, `findOwnedTask()` |
| `app/Http/Requests/Store|UpdateTaskRequest.php` | Validación (sin `user_id`) |
| `app/Exceptions/UserService*.php` | 503 / 502 tipados, renderizados en `bootstrap/app.php` |
| `resources/views/{layouts,auth,tasks,errors}` | Vistas, CSS inline, login, página 503 |
| `config/services.php` | Bloque `user_service` (`url`, `timeout`) |
| `tests/Feature/TaskApiTest.php` | 16 tests con `Http::fake()` |
| `tests/Feature/TaskUiTest.php` | 18 tests |

---

## 6. Cómo arrancarlo

```bash
# Terminal 1
cd user-service
composer install && cp .env.example .env
php artisan key:generate
touch database/db_users.sqlite
php artisan migrate:fresh --seed
php artisan serve --port=8000

# Terminal 2
cd task-service
composer install && cp .env.example .env
php artisan key:generate
touch database/db_tasks.sqlite
php artisan migrate:fresh --seed
php artisan serve --port=8001
```

Abre <http://127.0.0.1:8001/tasks>. Te llevará a `/login`.

**Credenciales del seeder:** `ana.torres@example.com` / `luis.ramirez@example.com`, contraseña
`password`.

> Las rutas de base de datos en `.env.example` son **relativas** (`database/db_users.sqlite`)
> para que el repo funcione en cualquier máquina.

---

## 7. Pruebas con cURL

```bash
# 1. Login: email + contraseña -> token opaco
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/tokens \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"ana.torres@example.com","password":"password"}' \
  | php -r 'echo json_decode(stream_get_contents(STDIN),true)["token"];')

# 2. Identidad, preguntada directamente al user-service
curl -s http://127.0.0.1:8000/api/user -H "Authorization: Bearer $TOKEN"
# {"id":1,"name":"Ana Torres","email":"ana.torres@example.com"}   [200]

# 3. Sin token -> 401
curl -i http://127.0.0.1:8001/api/tasks
# {"error":"Unauthenticated.","message":"Envía un token válido como: ..."}   [401]

# 4. Crear tarea -> el user_id sale de la identidad
curl -i -X POST http://127.0.0.1:8001/api/tasks \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"title":"Comprar el almuerzo"}'
# {"title":"Comprar el almuerzo","user_id":1,"status":"pending","id":1}   [201]

# 5. Intentar inyectar user_id -> se ignora
curl -X POST http://127.0.0.1:8001/api/tasks \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"title":"Secreto de Ana","user_id":999}'
# {"title":"Secreto de Ana","user_id":1,...}   [201]  <- el 999 se descartó

# 6. Listar
curl -s http://127.0.0.1:8001/api/tasks -H "Authorization: Bearer $TOKEN"

# 7. Otro usuario intenta tocar una tarea ajena -> 403
curl -i http://127.0.0.1:8001/api/tasks/1 -H "Authorization: Bearer $TOKEN_DE_LUIS"

# 8. Revocar el token
curl -i -X DELETE http://127.0.0.1:8000/api/tokens -H "Authorization: Bearer $TOKEN"
# [204]  y a partir de ahí el mismo token da 401

# 9. user-service apagado -> 503 en TODO
curl -i http://127.0.0.1:8001/api/tasks -H "Authorization: Bearer $TOKEN"
```

### Postman

| # | Method | URL | Notas |
|---|---|---|---|
| 1 | POST | `{{user_service}}/api/tokens` | Guardar `token` en una variable de entorno |
| 2 | GET | `{{user_service}}/api/user` | Header `Authorization: Bearer {{token}}` |
| 3 | GET | `{{task_service}}/api/tasks` |idem |
| 4 | POST | `{{task_service}}/api/tasks` | body `{"title":"Tarea"}` — **sin** `user_id` |
| 5 | PUT | `{{task_service}}/api/tasks/1` | body `{"title":"Nueva","status":"completed"}` |
| 6 | DELETE | `{{task_service}}/api/tasks/1` | |
| 7 | DELETE | `{{user_service}}/api/tokens` | logout |

```json
{
  "user_service": "http://127.0.0.1:8000",
  "task_service": "http://127.0.0.1:8001",
  "token": ""
}
```

---

## 8. Matriz de respuestas

### Del user-service

| Situación | Respuesta |
|---|---|
| Credenciales válidas | `201` + token en claro |
| Contraseña incorrecta | `401` "Credenciales inválidas" |
| Usuario inexistente | `401` (el mismo mensaje, a propósito) |
| Sin token en `/api/user` | `401` |
| Token caducado o revocado | `401` |

### Del task-service

| Situación | Respuesta | ¿Guarda? |
|---|---|---|
| Sin token (API) | `401` | No |
| Token inválido o revocado (API) | `401` | No |
| Sin sesión (web) | `302 → /login` | No |
| user-service apagado | `503` | No |
| user-service responde 500 | `502` | No |
| Payload inválido | `422` | No |
| Tarea de otro usuario | `403` | No |
| Token válido, tarea propia | `200` / `201` | **Sí** |

La distinción importante es la segunda contra la cuarta: **"no sé quién eres" y "no puedo
preguntarte quién eres"** son dos problemas distintos y merecen respuestas distintas. Confundirlos
convierte un simple "vuelve a loguearte" en un "el sistema está roto".

---

## 9. Tests

```bash
cd user-service && vendor/bin/pest --compact   # 16 tests
cd task-service && vendor/bin/pest --compact   # 34 tests, 88 asserts
```

Ninguno levanta el otro servicio: `Http::fake()` simula `200`, `401`, `404`, `500` y conexión
caída.

```php
// Simular que el user-service está vivo
function fakeUserService(?array $user = null): array
{
    Http::fake([
        '*/api/user'   => Http::response($user),
        '*/api/tokens' => Http::response(['token' => 'issued-token'], 201),
    ]);
    return $user;
}
```

Hay tests que intentan inyectar `user_id` por API y por formulario, y comprueban que se ignora.

---

## 10. La prueba que demuestra que es un sistema distribuido

Detén el user-service (`Ctrl+C`) y manda `GET /api/tasks` con un token válido. El task-service
responde `503`. Ninguna cantidad de código dentro del task-service pudo evitarlo: la identidad
**no está en su proceso**, está en otro proceso, detrás de otra base de datos, al otro lado de un
socket.

En la UI se ve igual de claro: `/tasks` deja de funcionar y muestra una página que explica que
lo que falta es el otro servicio, no esta aplicación.

Y a la inversa: borra `user-service/` completo y el task-service **sigue funcionando** para
listar, editar y borrar tareas. Solo pierde la creación de tareas nuevas y el inicio de sesión.
Esa es la disponibilidad parcial que da la independencia real entre servicios.

---

## 11. Decisiones de diseño (y por qué)

- **`tasks.user_id` sin llave foránea.** Una FK física exigiría el mismo motor de base de datos.
- **Toda la frontera de red en `UserServiceClient`.** URL, timeout y clasificación de errores en
  un solo archivo.
- **Dos excepciones de dominio, renderizadas en un solo lugar** (`bootstrap/app.php`). Ningún
  endpoint puede olvidarse de manejarlas.
- **Token opaco con SHA-256**, no JWT. Estado de identidad con un único dueño, revocación
  inmediata. El JWT se justifica cuando hay terceros validando; aquí no.
- **`findOwnedTask()` en vez de route model binding.** Evita la fuga 404/403 y centraliza el
  chequeo de propiedad.
- **Sin selector de usuario en el formulario.** Con auth, la tarea es de quien la crea.
- **Cero paso de build.** CSS inline en el layout, sin `@vite`. El proyecto no tiene
  `node_modules`, así que con Vite las páginas darían *"Unable to locate file in Vite manifest"*.

---

## 12. Limitaciones honestas

- **Un salto de red en cada request autenticado.** La latencia se suma a la de la base de datos.
- **Sin circuit breaker.** Con el user-service caído, cada request espera los 3 s del timeout.
- **La disponibilidad del task-service depende de la del user-service.** Si este cae, nadie
  puede autenticarse.
- **Los tokens no expiran por defecto.** La columna `expires_at` existe y el código la respeta,
  pero no hay TTL configurado.
- **Sin refresh tokens.** Hay que pedir uno nuevo con la contraseña cuando caduque.
- **Sin rate limiting en la emisión.** El login de la UI tiene `throttle:6,1`, pero
  `POST /api/tokens` del user-service **no** lo tiene. Es el endpoint que recibe contraseñas: el
  primero que debería estar limitado.
- **Una sola identidad.** No hay roles ni permisos; solo "es tuya o no es tuya".
- **Acoplamiento al contrato.** Si el user-service cambia la forma del 401, el task-service se
  entera al instante.

---

# PARTE 2 — Qué sigue después

> Nada de esta sección está implementado. Es una hoja de ruta con el razonamiento.

## 13. Por qué dejamos Lumen atrás

Lumen fue el framework "ligero" de Laravel para microservicios. **Laravel dejó de recomendarlo
para proyectos nuevos en 2021**, y así lo dice la documentación oficial:

> *"In the years since releasing Lumen, PHP has made a variety of wonderful performance
> improvements. For this reason, along with the availability of Laravel Octane, we no longer
> support beginning new projects with Lumen. Instead, we recommend always beginning new projects
> with Laravel."* — [lumen.laravel.com](https://lumen.laravel.com/)

La última versión estable es **v11.2.0 de noviembre de 2024**. Lumen solo sigue vivo powering
Laravel Vapor.

**Las tres razones:**

1. **Laravel alcanzó en rendimiento.** Route caching, OPcache y middleware cerraron la brecha.
2. **No tenía Sanctum, ni Passport, ni Blade.** Terminabas reinyectando a mano los componentes
   de Laravel, que es Lumen dejando de ser Lumen.
3. **Octane resolvió el problema para el que Lumen existía**, sin renunciar a nada.

**Consecuencia:** el argumento "es un microservicio, entonces Lumen" ya no se sostiene. La
velocidad se compra con Octane, no con otro framework.

---

## 14. De síncrono a asíncrono: RabbitMQ

Hoy cada request a task-service **suma** la latencia de user-service. Y `POST /api/tokens` es un
ejemplo perfecto: una operación de 30 ms en la que casi todo el tiempo es esperar a que otro
proceso despierte.

```
Cliente ──5ms──▶ task-service ──5ms──▶ user-service
       ◀────────        ◀────────
              Total ≈ 10 ms
```

**RabbitMQ** (el driver oficial es `vladimir-yuldashev/laravel-queue-rabbitmq`, v15, compatible
con Laravel 13) movería la parte no urgente fuera del camino crítico.

| Ganas | Pierdes |
|---|---|
| El cliente responde en ~5 ms | **El invariante "no se guarda nada sin validar" desaparece** |
| user-service caído ya no bloquea | Consistencia eventual |
| Reintentos automáticos y DLQ | Hay que manejar el estado "huérfana" |
| Picos se absorben en el broker | Idempotencia obligatoria |

> **Detalle que se olvida:** ese paquete soporta Octane desde la 13.3, pero exige declarar la
> conexión `rabbitmq` en el `warm` de `config/octane.php`.

**La pregunta que decide:** ¿es aceptable que exista una tarea sin usuario válido? Para
notificaciones, no hay duda. Para integridad crítica, el saga con compensación es el camino.

---

## 15. JWT, Sanctum y mTLS: cuándo cada uno

Hoy usamos **tokens opacos validados por HTTP**. Tres alternativas, no equivalentes:

- **Sanctum** (lo que trae Laravel): tokens opacos en base de datos, revocables, con abilities.
  Perfecto para SPAs y móviles. No para servicio-a-servicio: el user-service tendría que
  consultar su tabla en cada request.
- **JWT** (`php-open-source-saver/jwt-auth`, el fork mantenido de `tymon/jwt-auth`): stateless,
  sin salto de red. Pero revocar es un problema (hasta `exp` vale), el payload no es secreto, y
  el skew de reloj rompe `nbf`/`exp`. Si ya tienes base de datos, JWT no te compra nada.
- **mTLS o API Gateway**: la respuesta de producción. Separa la identidad del servicio de la del
  usuario, y elimina el salto por request.

**El orden sensato:** el que permite *ver* el problema primero. Montar mTLS antes de tener dos
servicios es pagar una factura que todavía no te llegó.

---

## 16. Laravel Octane

`php artisan serve` bootea Laravel entero en cada request. Octane (FrankenPHP, Swoole, OpenSwoole
o RoadRunner) lo deja residente en memoria.

```bash
composer require laravel/octane
php artisan octane:install --server=frankenphp
php artisan octane:start --workers=8
php artisan octane:reload     # tras un deploy, sin downtime
```

**Cuándo NO:** por debajo de ~500 requests/minuto la diferencia entre PHP-FPM y Octane es
imperceptible. Una API que recibe 50 req/min no tiene un problema de rendimiento; tiene un
problema de diseño.

**Los riesgos:** el estado vive entre requests (un `static` o un singleton con estado se comparte
entre usuarios del worker), el container no se reinicializa, y los logs se acumulan en memoria.

**En este proyecto no lo justifica todavía.** El user-service recibe 1 request por cada request
autenticado. Lo justificaría si el listado resolviera N llamadas de golpe.

---

## 17. Observabilidad

- **Correlación de requests:** un `X-Request-Id` generado en el borde y propagado a cada llamada
  saliente. Con eso, un `grep` en los logs de los dos servicios te arma la historia completa.
- **OpenTelemetry** para trazas distribuidas: una petición se convierte en un árbol de spans que
  cruza el límite de los servicios.
- **Logs estructurados** en JSON, con `user_id`, `task_id`, `status`, `duration`.
- **Métricas:** p95 de latencia y, sobre todo, **cuántas veces falla la autenticación**. Esa
  última es la que te avisa de que necesitas el circuit breaker.

---

## 18. Resiliencia: circuit breaker e idempotencia

**Circuit breaker.** Hoy, con el user-service caído, cada request espera 3 s. Con 50 usuarios son
50 hilos bloqueados. Un breaker memoriza el fallo: tras N errores seguidos deja de llamar y
falla de inmediato. De 3 s a 3 ms.

**Idempotencia.** `POST /api/tokens` no lo es: si el cliente no recibe la respuesta y reintenta,
crea un token de más. Con `Idempotency-Key` guardado junto a la respuesta, el reintento devuelve
la misma.

---

## 19. Un tercer servicio: qué se comparte y qué no

Con un `tags-service` al lado, la pregunta natural es si hay que duplicar el `AuthController`.
La respuesta corta: **el middleware sí se mantiene idéntico; el controller no debería
compartirse todavía.**

Y hay una tentación posterior: meter todo en una carpeta o paquete compartido. La guía:

- **Comparte el vocabulario:** el DTO `AuthenticatedUser`, el concepto de propiedad, el contrato
  de errores 503/502. No contienen decisiones de arquitectura.
- **Compón las decisiones:** "para validar quién es, hay que llamar por HTTP al user-service" es
  una decisión, y algún día quizá sea JWT o un gateway. Heredarla te impediría cambiar en un
  solo servicio.
- **Regla de tres:** no extraigas hasta la tercera copia.

El detalle completo está en [`flujo-autenticacion.html`](flujo-autenticacion.html), secciones
14 y 15.

---

## 20. Despliegue en contenedores

Cada servicio en su contenedor con su base. Tres cosas que se aprenden tarde:

1. **`depends_on` no espera disponibilidad, solo arranque.** Por eso existen los health checks.
2. **`USER_SERVICE_URL` no puede ser `localhost`** dentro de un contenedor: ahí solo está él
   mismo.
3. **Un contenedor no debe escribir en el sistema de archivos.** `storage/framework/*` como
   volúmenes compartidos.

---

## 21. Roadmap sugerido

| # | Paso | Por qué en este orden |
|---|---|---|
| 1 | **Rate limit en `POST /api/tokens`** | Recibe contraseñas. Es lo más urgente que queda. |
| 2 | **Métricas y `X-Request-Id`** | Antes de optimizar, saber qué pasa |
| 3 | **Circuit breaker + reintentos** | Convierte una caída de 3 s en una de 3 ms |
| 4 | **TTL y refresh tokens** | Los tokens no expiran: es una ventana abierta |
| 5 | **API versioning** (`/api/v1/...`) | Barato ahora, carísimo después |
| 6 | **Contratos de consumidor en CI** | Antes de que haya dos equipos |
| 7 | **RabbitMQ** | Solo si el punto 3 muestra que la latencia secuencial duele |
| 8 | **Docker + health checks** | Cuando el proyecto se compare con otros |
| 9 | **Octane** | Solo después de medir que el arranque es el cuello de botella |
| 10 | **JWT o gateway** | Solo si aparecen terceros validando, o si la caída del peer se nota |

Los pasos 1 a 3 cuestan horas y evitan incidentes. El 9 puede esperar un año sin que nadie lo
note.

---

## Documentos relacionados

| Archivo | Contenido |
|---|---|
| [`laravel-services.html`](laravel-services.html) | Services en Laravel: qué son y cuándo usarlos |
| [`flujo-autenticacion.html`](flujo-autenticacion.html) | El flujo de autenticación completo, un tercer servicio y código compartido |

---

*user-service `:8000` · db_users.sqlite — task-service `:8001` · db_tasks.sqlite*