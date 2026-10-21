# Sistema de Tareas Inteligente

**Dos microservicios Laravel independientes hablando por HTTP síncrono, con autenticación
delegada.** El ejemplo más pequeño que demuestra dos principios reales: cuando los servicios son
independientes, la única forma de validar un dato que "pertenece" al otro es preguntárselo por la
red; y cuando no tienes usuarios, la única forma de saber quién llama es preguntárselo también.

```
┌─────────────┐     ┌────────────────────────────────┐     ┌──────────────────────────┐
│             │     │                                │     │                          │
│  Cliente    │────▶│  task-service   :8001          │────▶│  user-service  :8000    │
│  curl /     │     │  db_tasks.sqlite               │ GET │  db_users.sqlite         │
│  Postman/UI │◀────│  API REST + UI web             │◀────│  /api/tokens  /api/user  │
│             │201  │  NO tiene tabla users          │200  │  (identidad)  headless   │
└─────────────┘     └────────────────────────────────┘     └──────────────────────────┘
```

Para saber quién llama, el task-service **no tiene usuarios**: le reenvía el token al
user-service por HTTP y pregunta. Si ese servicio responde `200`, sigue; si dice `401`, el token
no vale; si está caído, devuelve `503` y **nadie puede autenticarse**.

---

## Qué demuestra

- ✅ Aislamiento real de datos: **dos bases de datos, cero FKs cruzadas**
- ✅ Comunicación **síncrona por HTTP** en cada request (`UserServiceClient`)
- ✅ **Tokens opacos** con SHA-256: revocables de inmediato, sin JWT
- ✅ Un **middleware** que autentica contra el otro servicio (`AuthenticateWithUserService`)
- ✅ **Autorización por propiedad**: solo ves y tocas tus propias tareas
- ✅ **Errores tipados** (`UserServiceUnavailableException` / `UserServiceErrorException`) renderizados en un solo lugar
- ✅ **CRUD completo**: API REST + interfaz web Blade, ambos con el mismo cliente
- ✅ **Degradación controlada**: si el user-service cae, la UI lo explica en vez de romperse
- ✅ 50 tests / 126 asserts, sin levantar el otro servicio (`Http::fake()`)

## Requisitos

- PHP **8.3+** (probado en 8.4) · Composer 2 · extensión `pdo_sqlite` · Laravel **13**

---

## Instalación

```bash
git clone <este-repo> demo-microservicios
cd demo-microservicios

# --- user-service ---
cd user-service
composer install && cp .env.example .env && php artisan key:generate
touch database/db_users.sqlite
php artisan migrate:fresh --seed
php artisan serve --port=8000

# --- task-service (en otra terminal) ---
cd task-service
composer install && cp .env.example .env && php artisan key:generate
touch database/db_tasks.sqlite
php artisan migrate:fresh --seed
php artisan serve --port=8001
```

Abre **<http://127.0.0.1:8001/tasks>** → te llevará a `/login`.

| Email | Contraseña |
|---|---|
| `ana.torres@example.com` | `password` |
| `luis.ramirez@example.com` | `password` |

> Las rutas de base de datos en `.env.example` son **relativas**, para que el repo funcione en
> cualquier máquina. El archivo SQLite debe existir: `touch` o `migrate:fresh`.

> `composer install` en cada servicio también enlaza los paquetes de `packages/`
> (repositorio local de tipo `path`). Si da error de repositorio, revisa que las
> rutas `../packages/...` del `composer.json` apunten bien.

---

## Los dos servicios

| | user-service | task-service |
|---|---|---|
| Puerto | `http://127.0.0.1:8000` | `http://127.0.0.1:8001` |
| Base de datos | `database/db_users.sqlite` | `database/db_tasks.sqlite` |
| Tablas | `users`, `api_tokens` | `tasks`, `cache`, `jobs` |
| Rol | Identidad: emite y valida tokens | Tareas del usuario autenticado |
| ¿Conoce usuarios? | **Sí**, es el dueño | **No** — solo guarda el `user_id` |
| Interfaz | Ninguna (headless) | API REST **+ UI web** |
| Health check | `GET /health` · `GET /api/health` | `GET /api/health` · `GET /health-ui` |
| Tests | 33 (114 asserts) | 50 (114 asserts) |

---

## API

### user-service — identidad

| Método | Ruta | Body / Header | Respuesta |
|---|---|---|---|
| `POST` | `/api/tokens` | `{email, password, device_name?}` | `201` + `{token, token_type, expires_at, user}` |
| `GET` | `/api/user` | `Authorization: Bearer <token>` | `200` `{id, name, email}` · `401` |
| `DELETE` | `/api/tokens` | `Authorization: Bearer <token>` | `204` revoca el token |
| `GET` | `/api/users` | — | Lista paginada (abierto) |
| `GET` | `/api/users/{id}` | — | `200` · `404` (abierto) |

### task-service — tareas (todas requieren token)

| Método | API | UI web |
|---|---|---|
| `GET` | `/api/tasks` | `/tasks` |
| `POST` | `/api/tasks` | `/tasks/create` |
| `GET` | `/api/tasks/{id}` | `/tasks/{id}` |
| `PUT` | `/api/tasks/{id}` | `/tasks/{id}/edit` |
| `DELETE` | `/api/tasks/{id}` | `/tasks/{id}` |
| — | — | `/login` · `POST /login` · `POST /logout` |

### Matriz de respuestas

| Situación | Respuesta | ¿Guarda? |
|---|---|---|
| Sin token (API) | `401` | No |
| Token inválido o revocado | `401` | No |
| Sin sesión (web) | `302 → /login` | No |
| user-service apagado | `503` | No |
| user-service devuelve 500 | `502` | No |
| Payload inválido | `422` | No |
| Tarea de otro usuario | `403` | No |
| Token válido, tarea propia | `200` / `201` | **Sí** |

`"no sé quién eres"` y `"no puedo preguntarte quién eres"` son dos problemas distintos y
merecen respuestas distintas. Confundirlos convierte un "vuelve a loguearte" en un "el sistema
está roto".

---

## Pruebas rápidas con cURL

```bash
# 1. Login -> token opaco
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/tokens \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"ana.torres@example.com","password":"password"}' \
  | php -r 'echo json_decode(stream_get_contents(STDIN),true)["token"];')

# 2. Identidad
curl -s http://127.0.0.1:8000/api/user -H "Authorization: Bearer $TOKEN"
# {"id":1,"name":"Ana Torres","email":"ana.torres@example.com"}            [200]

# 3. Sin token -> 401
curl -i http://127.0.0.1:8001/api/tasks
# {"error":"Unauthenticated.",...}                                         [401]

# 4. Crear -> el user_id sale de la identidad
curl -i -X POST http://127.0.0.1:8001/api/tasks \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN" -d '{"title":"Comprar el almuerzo"}'
# {"title":"Comprar el almuerzo","user_id":1,"status":"pending","id":1}    [201]

# 5. Intentar inyectar user_id -> se ignora
curl -X POST http://127.0.0.1:8001/api/tasks \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN" -d '{"title":"Secreto de Ana","user_id":999}'
# {"title":"Secreto de Ana","user_id":1,...}                              [201]

# 6. Otro usuario intenta tocar una tarea ajena -> 403
curl -i http://127.0.0.1:8001/api/tasks/1 -H "Authorization: Bearer $TOKEN_DE_LUIS"

# 7. user-service apagado (Ctrl+C) -> 503 en TODO
curl -i http://127.0.0.1:8001/api/tasks -H "Authorization: Bearer $TOKEN"
```

**Postman**: variables `user_service`, `task_service` y `token`; el request 1 guarda el `token`
en la variable de entorno y el resto manda `Authorization: Bearer {{token}}`.

---

## Tests

```bash
cd user-service && vendor/bin/pest --compact   # 33 tests, 114 asserts
cd task-service && vendor/bin/pest --compact   # 50 tests, 114 asserts
```

Ningún test necesita el otro servicio: `Http::fake()` simula `200`, `401`, `404`, `500` y la
conexión caída. Hay tests que intentan inyectar `user_id` por API y por formulario, y comprueban
que se ignora.

Los paquetes de `packages/` también tienen cobertura propia, en
`task-service/tests/Feature/UserServiceClientPackageTest.php`: 11 tests sobre el cliente HTTP
del user-service (resolución de token válido, token inválido, error del peer, servicio caído,
emisión, revocación, propagación del `X-Request-Id` y el binding del contrato).

---

## Decisiones de diseño (y por qué)

**`tasks.user_id` no es una llave foránea.** Es un `unsignedBigInteger` indexado. Una FK física
exigiría el mismo motor de base de datos — que es justo lo que rompe el aislamiento.

**`api_tokens.user_id` sí tiene FK**, porque es integridad interna del user-service con su propia
tabla. Lo que no existe son FKs cruzadas.

**Token opaco con SHA-256, no JWT.** El estado de identidad tiene un único dueño y la revocación
es borrar una fila. El JWT se justifica cuando hay terceros validando; aquí no. Y con una
contraseña elegida por el usuario el hash sería Argon2: el token son 32 bytes de `random_bytes`,
no hay espacio de búsqueda.

**Toda la frontera de red vive en `UserServiceClient`.** URL, timeout, clasificación de errores.
`401` significa "token inválido" y se traduce a `null`; cualquier otro status es un fallo del peer
y lanza excepción.

**El código compartido está empaquetado, no copiado.** Los middlewares de observabilidad y el
cliente HTTP del user-service viven en `packages/` como paquetes Composer con repositorio `path`.
El `task-service` consume el **contrato** `IdentityProvider`, no la clase concreta, así que
cambiar la implementación no obliga a tocar los controllers.

**`findOwnedTask()` en vez de route model binding.** `SubstituteBindings` corre antes del
middleware de autenticación, así que con binding implícito un anónimo veía `404` para "no existe"
y `403` para "existe pero no es tuya": una fuga de información sobre tareas ajenas.

**No hay selector de usuario en el formulario.** Con autenticación la tarea es de quien la crea.

**Cero paso de build.** CSS inline en el layout, sin `@vite`. El proyecto no tiene
`node_modules`, así que con Vite las páginas darían *"Unable to locate file in Vite manifest"*.

**La validación de `user_id` desapareció, y es a propósito.** Antes `store()` llamaba al
user-service para preguntar si el id existía. Ahora esa misma pregunta la contesta el middleware,
en el mismo request. Preguntarlo dos veces es pagar la latencia dos veces.

---

## Estructura

```
demo-microservicios/
├── .gitignore
├── README.md
├── docs/                              ← toda la documentación
│   ├── FLUJO-MICROSERVICIOS.md           ← la referencia completa (con roadmap)
│   ├── FLUJO-MICROSERVICIOS.html         ← la misma, con diagrama y modo claro/oscuro
│   ├── FLUJO-MICROSERVICIOS-PLAIN.html   ← la misma, HTML sin estilos
│   ├── 01-estructura-base.html           ← el proyecto en 14 diapositivas
│   ├── 02-flujo-autenticacion.html       ← el flujo de auth, un 3er servicio y código compartido
│   ├── 03-rate-limit.html                ← el antes y el después del rate limiting
│   ├── 04-observabilidad.html            ← correlation ID, health checks y trazabilidad
│   ├── 05-modulos-composer.html          ← cómo empaquetar el código compartido con Composer
│   └── laravel-services.html             ← services en Laravel: qué son y cuándo usarlos
│
├── packages/                         ← código compartido, empaquetado con Composer
│   ├── microservices-observability/          ← correlation ID + contexto de logs
│   │   └── src/Http/Middleware/{EnsureRequestId,AttachLogContext}.php
│   └── microservices-user-service-client/    ← la frontera de red con el user-service
│       ├── src/Contracts/IdentityProvider.php
│       ├── src/UserServiceClient.php
│       ├── src/Exceptions/
│       └── config/user-service-client.php
│
├── user-service/                     ← :8000 · db_users.sqlite
│   ├── app/Models/ApiToken.php               ← emitir y buscar tokens
│   ├── app/Http/Controllers/AuthController.php
│   ├── app/Http/Controllers/HealthController.php
│   ├── app/Http/Middleware/AuthenticateApiToken.php
│   ├── database/migrations/…_create_api_tokens_table.php
│   └── tests/Feature/AuthApiTest.php
│
└── task-service/                     ← :8001 · db_tasks.sqlite
    ├── app/Http/Middleware/AuthenticateWithUserService.php
    ├── app/Support/AuthenticatedUser.php      ← identidad, solo durante el request
    ├── app/Http/Controllers/AuthController.php
    ├── app/Http/Controllers/HealthController.php
    ├── app/Http/Controllers/TaskController.php        ← UI web
    ├── app/Http/Controllers/Api/TaskController.php   ← API REST
    ├── resources/views/{layouts,auth,tasks,errors}/
    └── tests/Feature/{TaskApiTest,TaskUiTest,UserServiceClientPackageTest}.php
```

### Paquetes compartidos

El código que usaban los dos servicios ya no está duplicado: vive en `packages/`
como paquetes Composer reales, con su `composer.json`, su namespace y su
ServiceProvider.

```bash
# en cada servicio
composer install     # crea el symlink en vendor/demo/
```

Los paquetes se instalan desde un repositorio local de tipo `path`, así que un
cambio en `packages/` se ve al instante en los dos servicios, sin `composer update`.

El paquete de observabilidad publica sus middlewares como **aliases**
(`request.id` y `log.context`), así que las apps los usan por nombre y no
importan ninguna clase del paquete:

```php
// bootstrap/app.php
$middleware->appendToGroup('api', 'request.id');
$middleware->appendToGroup('api', 'log.context');
```

El paquete **no** decide en qué grupos se aplican: eso sigue en el
`bootstrap/app.php` de cada servicio, a la vista.


## Configuración

`task-service/.env`:

```dotenv
USER_SERVICE_URL=http://127.0.0.1:8000
USER_SERVICE_TIMEOUT=3
```

Nada de URLs hardcodeadas: si el user-service cambia de host, solo tocas el `.env`.

> Ojo: `php artisan serve` **no propaga variables de entorno arbitrarias** al proceso hijo. Si
> lanzas el servicio con `USER_SERVICE_URL=... php artisan serve`, seguirá leyendo el `.env`.

---

## Limitaciones honestas

- **Un salto de red en cada request autenticado.** La latencia se suma a la de la base de datos.
- **Sin circuit breaker.** Con el user-service caído, cada request espera los 3 s del timeout.
- **La disponibilidad del task-service depende de la del user-service.**
- **Sin refresh tokens, sin roles.** Solo "es tuya o no es tuya".
- **El rate limit vive en caché de ficheros.** Con `CACHE_STORE=file` el límite es por instancia:
  con varias réplicas, el atacante dispone de N presupuestos. En producción, Redis.
- **Sin `trustProxies`.** `X-Forwarded-For` se ignora, así que el límite por IP ve la IP real del
  balanceador. Es el primer paso antes de desplegar.
- **Los contadores de rate limit no distinguen usuarios.** El límite es por cuenta, no por
  persona: una cuenta compartida (familia, oficina) agota el presupuesto de todos.

## Y hacia dónde va

| # | Paso | Estado |
|---|---|---|
| 1 | Rate limit en `POST /api/tokens` (por cuenta y por IP) | **Hecho** |
| 2 | TTL de tokens (60 min, `expires_at` NOT NULL) | **Hecho** |
| 3 | `X-Request-Id` + health checks + logs con contexto | **Hecho** |
| 4 | Código compartido empaquetado con Composer | **Hecho** |
| 5 | Circuit breaker + reintentos con backoff | Pendiente |
| 6 | Refresh tokens | Pendiente |
| 7 | API versioning | Pendiente |
| 8 | Contratos de consumidor en CI | Pendiente |
| 9 | RabbitMQ (`user.deleted` → purgar tareas) | Pendiente |
| 10 | Docker + despliegue | Pendiente |
| 11 | Octane | Pendiente |
| 12 | JWT o gateway | Pendiente |

Los siguientes cuestan horas y evitan incidentes. El 11 puede esperar un año sin que nadie lo
note.

---

## Documentación

| Archivo | Contenido |
|---|---|
| [`docs/FLUJO-MICROSERVICIOS.md`](docs/FLUJO-MICROSERVICIOS.md) | La referencia completa, en Markdown |
| [`docs/FLUJO-MICROSERVICIOS.html`](docs/FLUJO-MICROSERVICIOS.html) | La misma, con diagrama y modo claro/oscuro |
| [`docs/FLUJO-MICROSERVICIOS-PLAIN.html`](docs/FLUJO-MICROSERVICIOS-PLAIN.html) | La misma, HTML sin estilos |
| [`docs/laravel-services.html`](docs/laravel-services.html) | Services en Laravel: qué son, por qué importan y cuándo usarlos |
| [`docs/02-flujo-autenticacion.html`](docs/02-flujo-autenticacion.html) | El flujo de autenticación completo, qué pasa con un tercer microservicio y cómo compartir código |
| [`docs/03-rate-limit.html`](docs/03-rate-limit.html) | El rate limiting del login: el antes, el después y por qué dos límites |
| [`docs/04-observabilidad.html`](docs/04-observabilidad.html) | Correlation ID, health checks y trazabilidad entre microservicios |
| [`docs/05-modulos-composer.html`](docs/05-modulos-composer.html) | Cómo empaquetar el código compartido en paquetes Composer locales |
| [`docs/01-estructura-base.html`](docs/01-estructura-base.html) | El proyecto entero en 14 diapositivas |

---

## Aprende Laravel a fondo

Si quieres ver el **curso completo de Laravel** con este mismo enfoque práctico — microservicios
reales, APIs, colas, testing y despliegue, paso a paso y sin atajos:

**<https://www.desarrollolibre.net/blog/laravel/curso-laravel>**

---

*user-service `:8000` · db_users.sqlite — task-service `:8001` · db_tasks.sqlite*