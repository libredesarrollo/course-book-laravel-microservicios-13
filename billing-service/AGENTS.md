# AGENTS.md — billing-service

Servicio receptor de la serie demo-microservicios. El mapa del proyecto, los contratos
compartidos (outbox, webhook firmado, broker) y los pitfalls caros viven en el
`AGENTS.md` de la **raíz del repo**; esta guía solo añade lo específico de billing.

## Factos del servicio

- **Puerto 8002** · health `GET /api/health` · BD sqlite `database/database.sqlite`
  (este directorio, no otro — un `queue:work` drena la BD donde corre).
- **Rol**: RECEPTOR del evento `task.completed` que emite task-service, y único dueño de
  las facturas.
- Cola: `invoices`. Con `QUEUE_CONNECTION=database` (mundo b0.9) los jobs viven en la
  tabla `jobs` de esta BD; con `QUEUE_CONNECTION=rabbitmq` (b0.10) viven en el broker
  (`docker compose up -d` en la raíz).

## Contrato del receptor (no romper)

`POST /api/events` con middleware `events.verify` (paquete `demo/microservices-events`):

- `500` si falta el secret; `401` si no está fresco (`max_age` 120 s) o la firma HMAC
  no pasa (`hash_equals`); `200 {"status":"duplicate"}` si ya se vio el `event_id`
  (anti-replay con `Cache::add`).
- Tipo conocido → `202` y encarga el trabajo: `CreateInvoiceFromEvent::dispatch(...)->onQueue('invoices')`.
  Tipo desconocido → `204`.
- La factura es única: `UNIQUE invoices.event_id` + `catch UniqueConstraintViolationException`.
  El mismo evento dos veces NO crea dos facturas — la idempotencia la resuelve aquí,
  el broker no la compra.
- `CreateInvoiceFromEvent` exige `user_id` en el payload: sin él, log de aviso y sin
  factura (no lanza).

## Secrets

- `TASK_EVENTS_SECRET` (este .env) debe ser IGUAL a `BILLING_EVENTS_SECRET` del
  `task-service/.env`; cambiar uno y no el otro rompe el webhook con `401`.
- `BILLING_CHARGE_PER_TASK=10.00` es el monto por tarea.
- Los tests usan sus propios secretos (ver `phpunit.xml`), no los de este .env.

## Comandos de este servicio

```bash
php artisan serve --port=8002                                # API + UI /facturas
php artisan queue:work --queue=invoices                      # drena facturas (db o rabbitmq según env)
php artisan queue:work --queue=invoices --stop-when-empty    # procesa y se apaga
php artisan queue:failed && php artisan queue:retry          # fallidos
```

## Estilo y pruebas (del proyecto)

- Tests: 17/53 (`php artisan test --compact`); `EventReceptionTest` firma de verdad con
  `Signature::sign` y corre con `QUEUE_CONNECTION=sync`, sqlite `:memory:` — **ningún
  test pide otro servicio ni el broker en pie**.
- Al terminar un cambio PHP: `vendor/bin/pint --dirty --format agent` aquí; para tocar
  `packages/*`, Pint es el de `task-service/vendor/bin/pint`.
- Comentarios en español, llaves siempre, promoción de constructor, PHPDoc con array
  shape. Git: ningún commit/push sin pedido explícito del usuario.
