# AGENTS.md — proyecto demo-microservicios

Guía para agentes que trabajen en este repo. Dentro de cada servicio manda el
`AGENTS.md` de Laravel Boost (`task-service/`, `user-service/`, `billing-service/`); este
archivo es el mapa del proyecto y las reglas que los tres comparten.

## Qué es este proyecto

Serie práctica de microservicios con Laravel (PHP 8.4, Laravel 13, Pest, Pint, sqlite).
Tres servicios desplegables por separado + código compartido en paquetes Composer locales:

```
demo-microservicios/
├── user-service/      ← :8000 · IdP (RS256 + JWKS, SSO, cookies, refresh de sesión web)
├── task-service/      ← :8001 · tareas; PRODUCTOR del evento task.completed (outbox)
├── billing-service/   ← :8002 · facturas; RECEPTOR del webhook firmado, idempotente
├── packages/          ← código compartido como path-repositories de Composer
│   ├── microservices-observability/   ← correlation ID + contexto de logs + health
│   ├── microservices-user-service-client/ ← frontera de red con el IdP (retry + breaker)
│   └── microservices-events/          ← el outbox: emite, reparte por webhook firmado
├── docker-compose.yml  ← RabbitMQ 4 (management), b0.10
└── docs/               ← serie de capítulos + FLUJO-MICROSERVICIOS (referencia)
```

| Servicio | Puerto | Health | BD sqlite | Cola |
|---|---|---|---|---|
| user-service | 8000 | `/api/health` | `database/database.sqlite` (dev), `db_users.sqlite` histórica | — (IdP no participa) |
| task-service | 8001 | `/api/health` | `database/database.sqlite` (dev), `db_tasks.sqlite` | `mails`, `default` |
| billing-service | 8002 | `/api/health` | `database/database.sqlite` | `invoices` |

> Pitfall número uno del proyecto: **cada proceso corre desde su directorio propio**.
> Un worker arrancado desde la raíz o desde otro servicio drena la cola o se conecta al
> SQLite equivocado, y un `php artisan serve` corrido en un directorio equivocado responde
> con el health check de otro servicio. Entra SIEMPRE al directorio del servicio antes de
> correr cualquier comando de artisan.

## Qué se ha visto (capítulos vivos)

| Capítulo | Piezas nuevas | Estado |
|---|---|---|
| b0.1–b0.5 | Rate limit en login, TTL de tokens, observabilidad (`X-Request-Id`, health, logs con contexto), paquetes Composer como path repos, reintentos + circuit breaker | Hecho |
| b0.7 | JWT stateless (RS256), JWKS, verificación local, refresh de sesión web, SSO + single logout | Hecho (refresh de tokens API sigue pendiente) |
| b0.9 | Colas + eventos: patrón outbox, webhook firmado con HMAC, at-least-once e idempotencia del receptor | Hecho |
| b0.10 | RabbitMQ 4: broker real vía `docker compose`, driver `vladimir-yuldashev/laravel-queue-rabbitmq` v15, swap `QUEUE_CONNECTION` | Hecho |

## Llevar arriba el sistema a mano (E2E)

```bash
# 1. Broker (solo para b0.10; sin él, QUEUE_CONNECTION=database drena la tabla jobs local)
docker compose up -d                            # raíz del repo; UI http://127.0.0.1:15672 (guest/guest)

# 2. Tres servidores, cada uno en su directorio
cd user-service    && php artisan serve --port=8000 &
cd task-service    && php artisan serve --port=8001 &
cd billing-service && php artisan serve --port=8002 &

# 3. Token JWT (RS256; RP verifican con JWKS)
curl -s -X POST http://127.0.0.1:8000/api/tokens -H "Content-Type: application/json" \
  -d '{"email":"ana.torres@example.com","password":"password"}'

# 4. Tarea completada vía API con el token
curl -s -X POST http://127.0.0.1:8001/api/tasks -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -d '{"title":"..."}'
curl -s -X PUT  http://127.0.0.1:8001/api/tasks/$ID -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -d '{"status":"completed"}'

# 5. Reparto del evento (o dejarlo al cron)
cd task-service && php artisan outbox:dispatch   # el cartero; sin schedule:work a mano

# 6. Workers, cada uno en su directorio
cd task-service    && php artisan queue:work --queue=mails,default
cd billing-service && php artisan queue:work --queue=invoices

# 7. Observar el broker
docker exec demo-rabbitmq rabbitmqctl list_queues --quiet name messages_ready messages_unacknowledged
```

## Contratos que no se rompen

**Outbox (task-service, productor).** `TaskCompletion::complete()` es idempotente (un PUT
repetido NO re-emite el evento) y emite dentro de la MISMA transacción de negocio
(`DB::transaction`): si la tarea no sobrevive, `OutboxEvent::record('task.completed', [...])`
tampoco. El mail (`SendTaskCompletedEmail`, cola `mails`) se despacha FUERA de la
transacción, después del commit. `complete()` espera `$actor` (array) como 3er argumento —
en `store()` es `[]`, en `update()` es `$request->validated()`. El mail `TaskCompletedMail`
NO implementa `ShouldQueue`: el job es la unidad de cola (antes quedó encolando el mailable
como un job fantasma en la cola `default` — no lo regreses).

**Webhook firmado (paquete microservices-events).** El `outbox:dispatch` (cartero) entrega
el evento al webhook subscriptor: HMAC SHA-256 sobre el CUERPO crudo con
`X-Event-Signature`, más `X-Event-Id`, `X-Event-Type`, `X-Event-Timestamp` (frescura) y
`X-Request-Id` (traza). El despachador lleva un intento por (evento, receptor) con
`firstOrCreate`, incrementa `attempts` antes de mandar, y captura
`ConnectionException` → `recordFailure` (estado pendiente + backoff `[10, 30, 120]` s,
`max_attempts 5` → `failed`). Un receptor CAÍDO pesa como retraso, no como pérdida.
Config por app en `config/events.php` solo con `subscribers` (url, secret, events);
defaults operativos (`timeout 3`, backoff, `max_age 120`) viven en el paquete.

**Receptor idempotente (billing-service).** `POST /api/events` con middleware
`events.verify`: `500` si falta el secret, `401` si no está fresco (`max_age`), `401` si la
firma no pasa (`hash_equals`), `200 {"status":"duplicate"}` si ya se vio el `event_id`
(check anti-replay con `Cache::add`). El conocido responde `202` y encarga el trabajo
(`CreateInvoiceFromEvent` a la cola `invoices`); el desconocido, `204`. La factura es única
por `UNIQUE invoices.event_id` + `catch UniqueConstraintViolationException`. La
responsabilidad se resuelve en el receptor: el broker (o el webhook at-least-once) NO
compra idempotencia.

**JWT (IdP).** El IdP firma RS256 y publica `/api/jwks.json`; los RPs verifican localmente
(jwks cacheada 3600 s, leeway 10, issuer `user-service`). IdP caído: el RP sigue
autenticando con el JWT (`200 OK` en ~7 ms).

**RabbitMQ (b0.10).** Driver `vladimir-yuldashev/laravel-queue-rabbitmq ^15.0.2`,
`QUEUE_CONNECTION=rabbitmq`, conexión declarada en `config/queue.php` (hosts leyendo
`RABBITMQ_*`). El driver AUTODECLARA las colas como `durable` al despachar; el backoff de
reintento se materializa como colas laterales `<cola>.delay.5000` con TTL + deadletter.
Redelivery del broker: si un consumidor muere sin ack (`kill -9`), el mensaje vuelve a
`ready` — eso NO incrementa los `attempts` de Laravel (van por separado). Los tests nunca
tocan el broker (`phpunit.xml` clava `QUEUE_CONNECTION=sync`); sin Docker, la vuelta atrás
es `QUEUE_CONNECTION=database`.

## Reglas compartidas (pitfalls caros)

1. **Dir primero, comando después.** Worker, `serve`, `outbox:dispatch`, `tinker`: desde
   el directorio del servicio. Los tests del paquete viven EN task-service
   (`EventOutboxTest`) y en billing (`EventReceptionTest`).
2. **Migraciones del paquete espejadas.** Si tocas
   `packages/microservices-events/database/migrations/0001_01_01_000003_create_outbox_tables.php`,
   replica byte a byte en `task-service/database/migrations/2026_11_04_113649_create_outbox_tables.php`
   (y viceversa). El caso `outbox_deliveries` sin `timestamps` rompió a mitad de sesión.
3. **Secrets emparejados.** `task-service/.env` `BILLING_EVENTS_SECRET` debe ser IGUAL a
   `billing-service/.env` `TASK_EVENTS_SECRET` (cambiar uno solo rompe el webhook con
   `401`). Los tests usan sus propios valores (`phpunit.xml` de cada servicio).
4. **Path repos.** `composer install` por servicio enlaza `../packages/*`; editar el
   paquete se refleja al instante. Cambios de `composer.json` del paquete piden
   `composer update` en los servicios; binario de Pint para `packages/*` es el de
   `task-service/vendor/bin/pint`.
5. **Tests herméticos.** sqlite `:memory:`, `QUEUE_CONNECTION=sync`, cache en array;
   `Http::fake()` para HTTP saliente, y el webhook receptor se prueba firmando de verdad
   con `Signature::sign`. Ningún test pide otro servicio en pie.
6. **Formato y estilo.** `vendor/bin/pint --dirty --format agent` en lo cambiado;
   comentarios en español, llaves siempre, promoción de constructor, PHPDoc con array
   shape, sin comentarios a lo gritado.
7. **Boost dentro del servicio.** Al trabajar en task/user, manda el `AGENTS.md` local:
   usa `config:show`, `route:list`, `php artisan test --compact`, `tinker --execute` con
   comillas simples.

## Documentación y commit

- Los `docs/NN-*.html` son la serie de capítulos + `docs/FLUJO-MICROSERVICIOS.html/.md`
  de referencia. Cada capítulo queda recap con los comandos reales usados.
- El README raíz actualizar por capítulo: bullets, tablas, roadmap y env. Los README de
  cada servicio llevan su sección "Colas y eventos (b0.9)" y la de "RabbitMQ (b0.10)".
- Los post-publicaciones (`post*.html`) se escriben en la VOZ del usuario (primera
  persona, español natural), con los comandos de paquetes/comandos incluidos. Después de
  escribir o reescribir HTML/docs largos, VOLVER A LEER el resultado completo y corregir
  texto espurio (caracteres CJK/cirílicos sueltos, pasajes sin sentido) — lo largo falla
  en el final.
- Git: **nadie commitea, tag, ni push sin pedido explícito del usuario**; los commits y
  tags los hace el usuario, tras subir su material.
