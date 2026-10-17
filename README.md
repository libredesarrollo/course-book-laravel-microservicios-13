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
| Tests | 16 | 34 (88 asserts) |

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
cd user-service && vendor/bin/pest --compact   # 16 tests
cd task-service && vendor/bin/pest --compact   # 34 tests
```

Ningún test necesita el otro servicio: `Http::fake()` simula `200`, `401`, `404`, `500` y la
conexión caída. Hay tests que intentan inyectar `user_id` por API y por formulario, y comprueban
que se ignora.

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
├── FLUJO-MICROSERVICIOS.md           ← documentación completa (con roadmap)
├── FLUJO-MICROSERVICIOS.html         ← la misma, con formato
├── FLUJO-MICROSERVICIOS-PLAIN.html   ← la misma, HTML sin estilos
├── laravel-services.html             ← services en Laravel: qué son y cuándo usarlos
├── flujo-autenticacion.html          ← el flujo de auth, un 3er servicio y código compartido
│
├── user-service/                     ← :8000 · db_users.sqlite
│   ├── app/Models/ApiToken.php               ← emitir y buscar tokens
│   ├── app/Http/Controllers/AuthController.php
│   ├── app/Http/Middleware/AuthenticateApiToken.php
│   ├── database/migrations/…_create_api_tokens_table.php
│   └── tests/Feature/AuthApiTest.php
│
└── task-service/                     ← :8001 · db_tasks.sqlite
    ├── app/Services/UserServiceClient.php     ← la frontera de red
    ├── app/Http/Middleware/AuthenticateWithUserService.php
    ├── app/Support/AuthenticatedUser.php      ← identidad, solo durante el request
    ├── app/Http/Controllers/AuthController.php
    ├── app/Http/Controllers/TaskController.php        ← UI web
    ├── app/Http/Controllers/Api/TaskController.php   ← API REST
    ├── resources/views/{layouts,auth,tasks,errors}/
    └── tests/Feature/{TaskApiTest,TaskUiTest}.php
```

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
- **Los tokens no expiran por defecto.** La columna existe y el código la respeta, pero no hay TTL.
- **Sin rate limiting en `POST /api/tokens`.** El login de la UI tiene `throttle:6,1`, pero el
  endpoint que recibe contraseñas no. Es lo más urgente que queda.
- **Sin refresh tokens, sin roles.** Solo "es tuya o no es tuya".

## Y hacia dónde va

| # | Paso |
|---|---|
| 1 | Rate limit en `POST /api/tokens` |
| 2 | Métricas y `X-Request-Id` |
| 3 | Circuit breaker + reintentos |
| 4 | TTL y refresh tokens |
| 5 | API versioning |
| 6 | Contratos de consumidor en CI |
| 7 | RabbitMQ |
| 8 | Docker + health checks |
| 9 | Octane |
| 10 | JWT o gateway |

Los tres primeros cuestan horas y evitan incidentes. El 9 puede esperar un año sin que nadie lo
note.

---

## Documentación

| Archivo | Contenido |
|---|---|
| [`FLUJO-MICROSERVICIOS.md`](FLUJO-MICROSERVICIOS.md) | La referencia completa, en Markdown |
| [`FLUJO-MICROSERVICIOS.html`](FLUJO-MICROSERVICIOS.html) | La misma, con diagrama y modo claro/oscuro |
| [`FLUJO-MICROSERVICIOS-PLAIN.html`](FLUJO-MICROSERVICIOS-PLAIN.html) | La misma, HTML sin estilos |
| [`laravel-services.html`](laravel-services.html) | Services en Laravel: qué son, por qué importan y cuándo usarlos |
| [`flujo-autenticacion.html`](flujo-autenticacion.html) | El flujo de autenticación completo, qué pasa con un tercer microservicio y cómo compartir código |

---

## Aprende Laravel a fondo

Si quieres ver el **curso completo de Laravel** con este mismo enfoque práctico — microservicios
reales, APIs, colas, testing y despliegue, paso a paso y sin atajos:

**<https://www.desarrollolibre.net/blog/laravel/curso-laravel>**

---

*user-service `:8000` · db_users.sqlite — task-service `:8001` · db_tasks.sqlite*