# Sistema de Tareas Inteligente

**Tres microservicios Laravel independientes con `user-service` como Identity Provider (IdP)
central.** El ejemplo más pequeño que demuestra tres principios reales: cuando los servicios son
independientes, la única forma de validar un dato que "pertenece" al otro es preguntárselo por la
red; cuando no tienes usuarios, la única forma de saber quién llama es preguntárselo también; y
cuando varios servicios comparten identidad, el login vive en **uno solo** y los demás son
relying parties.

```
┌─────────────┐     ┌────────────────────────────┐     ┌──────────────────────────┐
│             │     │  task-service   :8001      │     │  user-service  :8000     │
│  Navegador  │────▶│  db_tasks.sqlite           │────▶│  db_users.sqlite         │
│  curl /     │◀────│  API REST + UI web         │◀────│  IdP: /login /authorize  │
│  Postman    │     │  NO tiene tabla users      │     │  /api/tokens /api/user   │
│             │     └────────────────────────────┘     │  /api/sso/exchange       │
│             │     ┌────────────────────────────┐     └──────────────────────────┘
│             │────▶│  billing-service :8002     │────▶        ▲
│             │◀────│  db_billing.sqlite         │◀────        │
└─────────────┘     │  API REST + UI web         │  SSO redirect + backchannel
                    │  NO tiene tabla users      │
                    └────────────────────────────┘
```

Para saber quién llama, `task-service` y `billing-service` **no tienen usuarios**: le reenvían el
token al `user-service` por HTTP y preguntan. Si responde `200`, siguen; si dice `401`, el token no
vale; si está caído, devuelven `503`. Para iniciar sesión, el navegador va **siempre** al IdP: si
ya hay sesión SSO, el IdP emite un código de un solo uso y el RP lo canjea por su propio token.

---

## Qué demuestra

- ✅ Aislamiento real de datos: **tres bases de datos, cero FKs cruzadas**
- ✅ Comunicación **síncrona por HTTP** en cada request (`UserServiceClient`)
- ✅ **Tokens de API en dos modos**: opaco revocable de inmediato o JWT RS256 con expiración corta; el modo es una llave de config
- ✅ Un **middleware** que autentica contra el IdP (`AuthenticateWithUserService`)
- ✅ **Login único**: solo `user-service` tiene formulario, credenciales y tabla `users`
- ✅ **SSO por código de un solo uso** entre RPs, con whitelist de `redirect_uri`
- ✅ **Single logout**: cada RP delega el cierre en la sesión central del IdP
- ✅ **Autorización por propiedad**: solo ves y tocas tus propias tareas
- ✅ **Errores tipados** (`UserServiceUnavailableException` / `UserServiceErrorException`) renderizados en un solo lugar
- ✅ **CRUD completo**: API REST + interfaz web Blade, ambos con el mismo cliente
- ✅ **Degradación controlada**: si el user-service cae, la UI lo explica en vez de romperse
- ✅ **Resiliencia ante la caída del IdP**: reintento idempotente, circuit breaker (503 en ms) y caché de verificación del token
- ✅ **JWT stateless (b0.7)**: el IdP firma con RS256 y publica `/api/jwks.json`; los RPs verifican localmente — **medido: IdP caído y el RP sigue autenticando con `200` en ~5 ms**
- ✅ **Colas y eventos (b0.9)**: `task.completed` nace en el **outbox** dentro de la misma transacción que la tarea; `outbox:dispatch` lo entrega a billing por webhook firmado con HMAC (reintentos con backoff), billing responde `202` y la factura se crea en la cola `invoices` — **medido: `200 OK` al usuario en ~8 ms con billing muerto, y una única factura aunque el evento llegue dos veces**
- ✅ Mails desacoplados en cola (`mails`) y facturas en cola (`invoices`), con `QUEUE_CONNECTION=database` local
- ✅ **Broker real (b0.10)**: los mismos jobs viven en RabbitMQ con `QUEUE_CONNECTION=rabbitmq` (+ `docker compose up -d`) — **medido: el mensaje vuelve a `ready` tras `kill -9` de un consumidor y la factura no se duplica con el mismo `event_id`**
- ✅ 173 tests / 488 asserts, sin levantar los otros servicios (`Http::fake()`)

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

# --- billing-service (en otra terminal) ---
cd billing-service
composer install && cp .env.example .env && php artisan key:generate
touch database/db_billing.sqlite
php artisan migrate:fresh --seed
php artisan serve --port=8002
```

Abre **<http://127.0.0.1:8001/tasks>** (o `/facturas` en el 8002) → te llevará al login del IdP
en **<http://127.0.0.1:8000/login>**. Tras autenticarte, la sesión SSO se reutiliza en el otro
servicio: el segundo no vuelve a pedir contraseña.

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

## Los tres servicios

| | user-service | task-service | billing-service |
|---|---|---|---|
| Puerto | `:8000` | `:8001` | `:8002` |
| Base de datos | `db_users.sqlite` | `db_tasks.sqlite` | `db_billing.sqlite` |
| Tablas | `users`, `api_tokens`, `sso_tickets` | `tasks`, `cache`, `jobs`, `outbox_events`, `outbox_deliveries` | `invoices`, `cache`, `jobs` |
| Rol | **IdP**: login, SSO, emite y valida tokens | Tareas del usuario autenticado | Facturas del usuario autenticado |
| ¿Conoce usuarios? | **Sí**, es el dueño | **No** — solo guarda el `user_id` | **No** — solo usa la identidad |
| Interfaz | Login + `/authorize` + API | API REST **+ UI web** | API REST **+ UI web** |
| Health check | `GET /health` | `GET /api/health` · `GET /health-ui` | `GET /api/health` · `GET /health-ui` |

---

## API

### user-service — identidad (IdP)

| Método | Ruta | Body / Header | Respuesta |
|---|---|---|---|
| `GET` | `/login` | — | Formulario (único del sistema) |
| `POST` | `/login` | `{email, password, client?, redirect_uri?}` | `302` al RP con `?code=...` |
| `GET` | `/authorize` | `?client=&redirect_uri=` | `302` al RP con `?code=...` (o al login) |
| `GET`/`POST` | `/logout` | `?redirect_uri=` | Cierra la sesión SSO y vuelve al RP |
| `POST` | `/api/sso/exchange` | `{code, client}` | `201` + `{token, token_type, expires_at, user}` · `400` |
| `POST` | `/api/tokens` | `{email, password, device_name?}` | `201` + `{token, token_type, expires_at, user}` |
| `GET` | `/api/user` | `Authorization: Bearer <token>` | `200` `{id, name, email}` · `401` |
| `DELETE` | `/api/tokens` | `Authorization: Bearer <token>` | `204` revoca el token |
| `GET` | `/api/users` | — | Lista paginada (abierto) |
| `GET` | `/api/users/{id}` | — | `200` · `404` (abierto) |

`POST /api/tokens` se mantiene para clientes que **no son navegador** (CLI, Postman, apps
móviles). El flujo web usa el código SSO.

### task-service — tareas (todas requieren token)

| Método | API | UI web |
|---|---|---|
| `GET` | `/api/tasks` | `/tasks` |
| `POST` | `/api/tasks` | `/tasks/create` |
| `GET` | `/api/tasks/{id}` | `/tasks/{id}` |
| `PUT` | `/api/tasks/{id}` | `/tasks/{id}/edit` |
| `DELETE` | `/api/tasks/{id}` | `/tasks/{id}` |
| — | — | `/sso/start` · `/sso/callback` · `POST /logout` |

### billing-service — facturas

| Método | API | UI web |
|---|---|---|
| `GET` | `/api/invoices` | `/facturas` |
| `POST` | `/api/events` (`events.verify`) | — |
| — | — | `/sso/start` · `/sso/callback` · `POST /logout` |

`POST /api/events` es el receptor de eventos: recibe `{event_id, type,
occurred_at, payload}` con los headers HMAC (`X-Event-Signature`,
`X-Event-Id`, `X-Event-Type`, `X-Event-Timestamp`). Firma inválida o headers
viejos → `401`; firma válida → `202` y el trabajo corre en la cola; evento
repetido → `200` `{"status":"duplicate"}` sin crear nada.

### Matriz de respuestas

| Situación | Respuesta | ¿Guarda? |
|---|---|---|
| Sin token (API) | `401` | No |
| Token inválido o revocado | `401` | No |
| Sin sesión (web) | `302 → /sso/start → /login` (IdP) | No |
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

# 7. user-service apagado (Ctrl+C):
#    - con TOKEN_MODE=opaque -> 503: el primero en ~340 ms, los demás en ~5 ms
#      (circuito abierto). Con la caché de verificación viva (60 s), 200.
#    - con TOKEN_MODE=jwt -> 200 en ~5 ms SIEMPRE: la verificación es local
#      (firma RS256 con la clave pública cacheada del endpoint JWKS).
curl -i http://127.0.0.1:8001/api/tasks -H "Authorization: Bearer $TOKEN"
```

```bash
# 8. Login y decodificación del JWT, para verlo por dentro: header.payload.signature.
#    El payload trae sub/name/email/iat/exp/jti; el header, kid y alg RS256.
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/tokens \
  -H "Content-Type: application/json" \
  -d '{"email":"ana.torres@example.com","password":"password"}' | python3 -c "import json,sys;print(json.load(sys.stdin)['token'])")
echo $TOKEN
curl -s http://127.0.0.1:8000/api/jwks.json | python3 -m json.tool
curl http://127.0.0.1:8001/api/tasks -H "Authorization: Bearer $TOKEN"
```

```bash
# 9. Colas y eventos: completa una tarea y el evento cruza los servicios
#    sin que el usuario note. Necesita workers (ver "Los workers" abajo).
TASK_ID=$(curl -s -X POST http://127.0.0.1:8001/api/tasks \
  -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{"title":"Instalar el grifo del baño"}' | python3 -c "import json,sys;print(json.load(sys.stdin)['id'])")

curl -s -X PUT http://127.0.0.1:8001/api/tasks/$TASK_ID \
  -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{"status":"completed"}' > /dev/null        # 200, ya. El mail va en cola.

cd task-service

echo "1. El evento nació en el outbox, en la misma transacción del PUT:"
php artisan tinker --execute 'print_r(\Illuminate\Support\Facades\DB::table("outbox_events")->latest("id")->first());'

php artisan outbox:dispatch
#  Entregas nuevas: 1 (~60 ms)   <- webhook firmado, billing responde 202

php artisan queue:work --queue=mails --stop-when-empty
#  App\Jobs\SendTaskCompletedEmail .. DONE

echo "2. Que el worker de billing facture:"
cd ../billing-service && php artisan queue:work --queue=invoices --stop-when-empty
#  App\Jobs\CreateInvoiceFromEvent .. DONE

echo "3. La factura (una sola, aunque re-envíes el evento):"
curl -s http://127.0.0.1:8002/api/invoices -H "Authorization: Bearer $TOKEN"
```

**Postman**: variables `user_service`, `task_service` y `token`; el request 1 guarda el `token`
en la variable de entorno y el resto manda `Authorization: Bearer {{token}}`.

---

## Los workers

Con `QUEUE_CONNECTION=database` el desacoplamiento es local: los jobs viven en
la tabla `jobs` de cada base de datos. Sin worker, los jobs **esperan**; esa es
exactamente la gracia del capítulo. Para que trabajen (cada comando en **su**
directorio — un worker drena la BD del servicio donde arranca):

```bash
# terminal task-service: mails + cron local del outbox
php artisan queue:work --queue=mails,default
php artisan schedule:work          # corre outbox:dispatch cada minuto

# terminal billing-service: facturas
php artisan queue:work --queue=invoices
```

`outbox:dispatch` no es un worker: es el "cartero" que reparte eventos del
outbox a los webhooks subscriptores. Con `schedule:work` corre cada minuto
(`withoutOverlapping`); a mano, `php artisan outbox:dispatch` en task-service.
Los tres README de los servicios documentan sus comandos en "Colas y eventos
(b0.9)".

### El broker de los b0.10 (RabbitMQ)

Con `QUEUE_CONNECTION=rabbitmq` (b0.10) los mismos jobs viven en el
broker y no en la tabla `jobs`. El broker se pone en pie con Docker en la raíz
del repo:

```bash
docker compose up -d               # rabbitmq:4-management; UI en http://127.0.0.1:15672 (guest/guest)
docker exec demo-rabbitmq rabbitmqctl list_queues --quiet name messages_ready messages_unacknowledged
```

Los comandos de worker **no cambian**: leen la conexión de `QUEUE_CONNECTION`;
solo asegúrate de que el broker esté arriba (`docker compose up -d`) antes de
arrancar workers. Sin broker en pie, vuelve a `QUEUE_CONNECTION=database` y el
sistema drena como en b0.9 — la tabla `jobs` sigue en pie para eso.

---

## Tests

```bash
cd user-service && vendor/bin/pest --compact    # 65 tests, 220 asserts
cd task-service && vendor/bin/pest --compact    # 91 tests, 215 asserts
cd billing-service && vendor/bin/pest --compact # 17 tests, 53 asserts
```

Ningún test necesita el otro servicio: `Http::fake()` simula `200`, `401`, `404`, `500` y la
conexión caída; el webhook de los eventos se firma igual que lo haría el
productor real (`Signature::sign`). Hay tests que intentan inyectar `user_id`
por API y por formulario, y comprueban que se ignora. El receptor de eventos
comprueba las cinco caras: firma rota `401`, headers viejos `401`, entrega
válida `202` con job en la cola, tipo desconocido `204` y evento repetido que
no duplica la factura (UNIQUE en `invoices`, con el job corriendo de verdad).

Los paquetes de `packages/` también tienen cobertura propia, en
`task-service/tests/Feature/UserServiceClientPackageTest.php`: tests sobre el cliente HTTP del
user-service (resolución de token válido, token inválido, error del peer, servicio caído, emisión,
revocación, propagación del `X-Request-Id` y el binding del contrato), sobre el middleware
empaquetado (alias registrado, `401` en API, redirect en web, sesión descartada con token
inválido), el ciclo de vida de `TokenSession` (login, logout y logout con el peer caído) y la
**resiliencia** (reintento ante 5xx idempotente, `4xx`/POST que no se reintentan, circuito que
abre tras el umbral y se recupera tras el cooldown, y caché de verificación positiva y negativa).

`billing-service` también tiene pruebas web: arranque del flujo SSO, canje del código, acceso a
`/facturas` y logout delegado al IdP (`billing-service/tests/Feature/InvoiceWebTest.php`).

`user-service` cubre el flujo completo del IdP en `tests/Feature/SsoFlowTest.php`: login,
`/authorize`, whitelist de clientes y `redirect_uri`, canje válido, código reutilizado, expirado,
de otro cliente o inexistente, y logout.

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

**El código compartido está empaquetado, no copiado.** Los middlewares de observabilidad, el
health check y el cliente HTTP del user-service viven en `packages/` como paquetes Composer con
repositorio `path`. El `task-service` consume el **contrato** `IdentityProvider`, no la clase
concreta, así que cambiar la implementación no obliga a tocar los controllers.

**La frontera con el user-service está completa en el paquete.** No solo el cliente HTTP: también
el middleware que autentica, la identidad que devuelve y el ciclo de vida de la sesión con token
remoto. Lo que queda en la aplicación es lo que es de la aplicación: las vistas, las validaciones
y las redirecciones.

**`findOwnedTask()` en vez de route model binding.** `SubstituteBindings` corre antes del
middleware de autenticación, así que con binding implícito un anónimo veía `404` para "no existe"
y `403` para "existe pero no es tuya": una fuga de información sobre tareas ajenas.

**No hay selector de usuario en el formulario.** Con autenticación la tarea es de quien la crea.

**Cero paso de build.** CSS inline en el layout, sin `@vite`. El proyecto no tiene
`node_modules`, así que con Vite las páginas darían *"Unable to locate file in Vite manifest"*.

**La validación de `user_id` desapareció, y es a propósito.** Antes `store()` llamaba al
user-service para preguntar si el id existía. Ahora esa misma pregunta la contesta el middleware,
en el mismo request. Preguntarlo dos veces es pagar la latencia dos veces.

**El evento nace en un outbox, no en un webhook.** Un webhook directo en el
mismo request es at-least-once al revés: nace-si-se-puede. Con la fila
`outbox_events` en la misma transacción que la tarea, el evento vive si el
negocio vivió. `outbox:dispatch` es el que cruza la red; con billing caído
simplemente no cruza, y no hace daño.

**El receptor resuelve "una o muchas veces".** Como el reparto es at-least-once,
el mismo evento puede llegar dos veces. La cuenta la lleva billing: cache
anti-replay por `event_id` y UNIQUE en `invoices.event_id`. La primera línea
ahorra trabajo redundante; la última hace la garantía real. Con at-least-once
al emisor y "exactamente una vez" al receptor el sistema queda usable.

**La cola local gana para el primer capítulo.** Con `QUEUE_CONNECTION=database`
cada servicio drena su propia tabla `jobs`, sin broker externo. Cuando el
equipo ya tiene el patrón outbox y la idempotencia, migrar a RabbitMQ es
cambiar el `config("queue")`: la aplicación no se entera — y b0.10 lo demostró
con el driver swap a `QUEUE_CONNECTION=rabbitmq`.

---

## Estructura

```
demo-microservicios/
├── .gitignore
├── AGENTS.md                          ← mapa del proyecto y reglas compartidas (agentes)
├── README.md
├── docker-compose.yml                  ← el broker de b0.10 (rabbitmq:4-management)
├── docs/                              ← toda la documentación
│   ├── FLUJO-MICROSERVICIOS.md           ← la referencia completa (con roadmap)
│   ├── FLUJO-MICROSERVICIOS.html         ← la misma, con diagrama y modo claro/oscuro
│   ├── FLUJO-MICROSERVICIOS-PLAIN.html   ← la misma, HTML sin estilos
│   ├── 01-estructura-base.html           ← el proyecto en 14 diapositivas
│   ├── 02-flujo-autenticacion.html       ← el flujo de auth, un 3er servicio y código compartido
│   ├── 03-rate-limit.html                ← el antes y el después del rate limiting
│   ├── 04-observabilidad.html            ← correlation ID, health checks y trazabilidad
│   ├── 05-modulos-composer.html          ← cómo empaquetar el código compartido con Composer
│   ├── 08-que-se-hizo-sso.html           ← el capítulo SSO: flujo, canje de código y cookie
│   ├── 09-resiliencia.html               ← retries, circuit breaker y caché ante la caída del IdP
│   └── laravel-services.html             ← services en Laravel: qué son y cuándo usarlos
│
├── packages/                         ← código compartido, empaquetado con Composer
│   ├── microservices-observability/          ← correlation ID + contexto de logs + health check
│   │   └── src/
│   │       ├── Http/Middleware/{EnsureRequestId,AttachLogContext}.php
│   │       └── Http/Controllers/HealthController.php
│   ├── microservices-user-service-client/    ← la frontera de red con el user-service
│   │   ├── src/Contracts/IdentityProvider.php
│   │   ├── src/UserServiceClient.php         ← con reintentos y breaker en send()
│   │   ├── src/Resiliency/CircuitBreaker.php  ← breaker respaldado por caché
│   │   ├── src/AuthenticatedUser.php        ← la identidad que confirmó el peer
│   │   ├── src/TokenSession.php             ← sesión web respaldada por token remoto
│   │   ├── src/Http/Middleware/AuthenticateWithUserService.php  ← con caché de verificación
│   │   ├── src/Exceptions/
│   │   └── config/user-service-client.php
│   └── microservices-events/                 ← la frontera asincrónica (b0.9)
│       ├── src/Outbox/OutboxEvent.php         ← evento: emisión en la propia BD
│       ├── src/Outbox/OutboxDelivery.php      ← intentos por receptor
│       ├── src/Outbox/OutboxDispatcher.php    ← reparto firmado con reintentos
│       ├── src/Outbox/Signature.php           ← HMAC SHA-256 del cuerpo
│       ├── src/Http/Middleware/VerifyEventSignature.php  ← middleware receptor
│       ├── src/Console/DispatchOutbox.php     ← comando outbox:dispatch
│       ├── database/migrations/               ← outbox_events / outbox_deliveries
│       └── config/events.php
│
├── user-service/                     ← :8000 · db_users.sqlite · IdP
│   ├── app/Models/{ApiToken,SsoTicket}.php
│   ├── app/Services/SsoTicketIssuer.php      ← valida client/redirect y emite códigos
│   ├── app/Http/Controllers/WebLoginController.php
│   ├── app/Http/Controllers/SsoController.php  ← authorize + exchange
│   ├── resources/views/auth/login.blade.php    ← el único login del sistema
│   ├── config/sso.php                          ← whitelist de clientes y TTL
│   └── tests/Feature/{AuthApiTest,SsoFlowTest,LoginThrottleTest}.php
│
├── task-service/                     ← :8001 · db_tasks.sqlite
│   ├── app/Http/Controllers/TaskController.php        ← UI web
│   ├── app/Http/Controllers/Api/TaskController.php   ← API REST
│   ├── resources/views/{layouts,tasks,errors}/
│   └── tests/Feature/{TaskApiTest,TaskUiTest,UserServiceClientPackageTest}.php
│
└── billing-service/                  ← :8002 · db_billing.sqlite
    ├── app/Http/Controllers/InvoiceController.php
    ├── resources/views/{layouts,invoices,errors}/
    └── tests/Feature/InvoiceWebTest.php
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

El paquete del cliente hace lo mismo con su middleware, bajo el alias
`auth.user-service`. Y expone las piezas que antes vivían en el `task-service`:
el propio middleware, la identidad (`AuthenticatedUser`), el ciclo de vida de la
sesión con token remoto (`TokenSession`) y los **tres controllers del flujo SSO**
(`SsoStartController`, `SsoCallbackController`, `SsoLogoutController`).

```php
// bootstrap/app.php
$middleware->alias(['auth.user-service' => \Demo\Microservices\UserServiceClient\Http\Middleware\AuthenticateWithUserService::class]);
```

En realidad **no hace falta escribir eso**: el paquete registra el alias si la
aplicación no ha declarado otro con el mismo nombre. La línea de arriba solo está
si quieres un nombre distinto.

El `HealthController` también viene en el paquete de observabilidad, así que los
tres servicios responden el health check exactamente igual. Lo que decide cada
aplicación es la ruta en la que lo monta.


## Configuración

`task-service/.env` y `billing-service/.env`:

```dotenv
USER_SERVICE_URL=http://127.0.0.1:8000
USER_SERVICE_TIMEOUT=3
USER_SERVICE_CLIENT=task        # billing en el otro servicio
USER_SERVICE_SSO_START_ROUTE=sso.start
# USER_SERVICE_CALLBACK_URL=   # opcional; por defecto url('/sso/callback')

# Resiliencia: reintentos idempotentes, caché del resultado de verificar
# el token y circuit breaker. Defaults razonables; 0 desactiva cada pieza.
USER_SERVICE_RETRIES=2
USER_SERVICE_RETRY_DELAY_MS=150
USER_SERVICE_CACHE_TTL=60
USER_SERVICE_CIRCUIT_THRESHOLD=3
USER_SERVICE_CIRCUIT_COOLDOWN=30

# JWT stateless (b0.7): el RP baja una vez la clave pública del IdP
# (JWKS) y verifica firmas localmente. Con 0 en el caché-ttl de la JWKS
# se pregunta por HTTP en cada verificación.
USER_SERVICE_JWKS_URL=http://127.0.0.1:8000/api/jwks.json
USER_SERVICE_JWT_ISSUER=user-service
USER_SERVICE_JWT_LEEWAY=10
USER_SERVICE_JWKS_CACHE_TTL=3600

# Colas: database (b0.9) drena la tabla jobs local; rabbitmq (b0.10)
# depende del broker del docker-compose (host/port/user de Rabbit abajo).
QUEUE_CONNECTION=rabbitmq

# RabbitMQ b0.10 — defaults que coinciden con el docker-compose de la raíz
RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/

# Eventos (b0.9) en task-service (productor)…
BILLING_EVENTS_URL=http://127.0.0.1:8002/api/events
BILLING_EVENTS_SECRET=secreto-task-billing-local

# …y en billing-service (receptor, con la misma semilla secreta).
TASK_EVENTS_SECRET=secreto-task-billing-local
BILLING_CHARGE_PER_TASK=10.00
```

En el lado del IdP hay tres llaves más (`user-service/.env`):

```bash
TOKEN_MODE=jwt            # jwt | opaque
JWT_TTL_MINUTES=15        # vida del access token; corto: no hay revocación
# php artisan user-service:jwt-keys   # genera el par RS256 en storage/app/jwt
```

Nada de URLs hardcodeadas: si el user-service cambia de host, solo tocas el `.env`.

`USER_SERVICE_CLIENT` es el identificador con el que el RP se presenta ante el IdP. El IdP lo
valida contra la whitelist de `user-service/config/sso.php`, que también fija qué `redirect_uri`
se acepta por cliente: sin eso, `/authorize` sería un open redirect.

`USER_SERVICE_SSO_START_ROUTE` es la ruta local que arranca el flujo SSO. Si se deja vacía, el
middleware responde `401` en JSON, que es lo que necesita una API pura.

> Ojo: `php artisan serve` **no propaga variables de entorno arbitrarias** al proceso hijo. Si
> lanzas el servicio con `USER_SERVICE_URL=... php artisan serve`, seguirá leyendo el `.env`.

### Secrets de los eventos

Cada par productor↔receptor comparte su clave HMAC, con **nombres distintos en
cada lado**: `BILLING_EVENTS_SECRET` es la que task usa para firmar,
`TASK_EVENTS_SECRET` es la que billing usa para verificar. El valor es el
mismo, pero el nombre es del lado que lo usa: el paquete no pisa la política
de nombres de cada aplicación.

---

## Limitaciones honestas

- **Un salto de red en cada request autenticado… solo si `TOKEN_MODE=opaque`.** Con JWT la verificación es local (cero red por request); el salto queda en la JWKS de arranque y en el fallback de tokens opacos antiguos.
- **La revocación de JWT no existe: expira.** `DELETE /api/tokens` con JWT es un no-op honesto; un token robado vive hasta el TTL (15 min). Es la regla del stateless y se paga con `JWT_TTL_MINUTES` corto. Con modo opaco, en cambio, la revocación es inmediata (borrado de fila).
- **La caché de verificación tiene hasta 60 s de ventana.** Un token revocado puede seguir autorizando hasta `USER_SERVICE_CACHE_TTL` segundos. Es el precio de la pieza y se ajusta en el `.env` (o a `0` para desactivarla).
- **Sin refresh tokens, sin roles.** Solo "es tuya o no es tuya". Con JWT de 15 min, el usuario vuelve a loguearse cada cuarto de hora en flujos de API puro; el flujo web lo disimula con la sesión del IdP.
- **El rate limit vive en caché de ficheros.** Con `CACHE_STORE=file` el límite es por instancia:
  con varias réplicas, el atacante dispone de N presupuestos. En producción, Redis.
- **Sin `trustProxies`.** `X-Forwarded-For` se ignora, así que el límite por IP ve la IP real del
  balanceador. Es el primer paso antes de desplegar.
- **Los contadores de rate limit no distinguen usuarios.** El límite es por cuenta, no por
  persona: una cuenta compartida (familia, oficina) agota el presupuesto de todos.
- **El single logout es central, no federado.** Al cerrar sesión, el RP redirige al `/logout`
  del IdP; las sesiones locales de los RPs ya visitados se invalidan en su siguiente request,
  no con un back-channel. Un OIDC real usa front/back-channel logout.
- **La whitelist de clientes vive en configuración.** Añadir un RP exige tocar
  `user-service/config/sso.php`; en un IdP real sería un registro de clientes en base de datos.
- **Sin PKCE ni refresh tokens.** El código SSO es de un solo uso, caduca en 60 s y va hasheado,
  pero el flujo está simplificado: no hay `state`, `nonce` ni verificación de code challenge.

## Y hacia dónde va

| # | Paso | Estado |
|---|---|---|
| 1 | Rate limit en `POST /api/tokens` (por cuenta y por IP) | **Hecho** |
| 2 | TTL de tokens (60 min, `expires_at` NOT NULL) | **Hecho** |
| 3 | `X-Request-Id` + health checks + logs con contexto | **Hecho** |
| 4 | Código compartido empaquetado con Composer | **Hecho** |
| 4b | SSO con IdP central + single logout | **Hecho** |
| 5 | Circuit breaker + reintentos con backoff | **Hecho** |
| 6 | Refresh tokens | Pendiente |
| 7 | API versioning | Pendiente |
| 8 | Contratos de consumidor en CI | Pendiente |
| 9a | Colas + eventos con outbox, webhook firmado y at-least-once idempotente | **Hecho** |
| 9b | RabbitMQ/Kafka: el mismo patrón con mensajería dedicada | **Hecho** (b0.10: RabbitMQ, driver swap) |
| 10 | Docker + despliegue | Pendiente |
| 11 | Octane | Pendiente |

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
| [`docs/08-que-se-hizo-sso.html`](docs/08-que-se-hizo-sso.html) | Recap del capítulo SSO: flujo, canje, cookie y logout central |
| [`docs/09-resiliencia.html`](docs/09-resiliencia.html) | Recap del capítulo resiliencia: reintentos, breaker y caché |
| [`docs/10-jwt-stateless.html`](docs/10-jwt-stateless.html) | Recap del capítulo JWT: firma del IdP, JWKS y verificación local |
| [`docs/11-colas-y-eventos.html`](docs/11-colas-y-eventos.html) | Recap del capítulo colas: outbox, webhook firmado e idempotencia |
| [`docs/12-rabbitmq.html`](docs/12-rabbitmq.html) | Recap del capítulo b0.10: el broker con docker compose, el driver y la redelivery |
| [`docs/01-estructura-base.html`](docs/01-estructura-base.html) | El proyecto entero en 14 diapositivas |

---

## Aprende Laravel a fondo

Si quieres ver el **curso completo de Laravel** con este mismo enfoque práctico — microservicios
reales, APIs, colas, testing y despliegue, paso a paso y sin atajos:

**<https://www.desarrollolibre.net/blog/laravel/curso-laravel>**

---

*user-service `:8000` (IdP) · task-service `:8001` · billing-service `:8002`*