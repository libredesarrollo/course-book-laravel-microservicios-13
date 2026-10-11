# Sistema de Tareas Inteligente

**Dos microservicios Laravel independientes hablando por HTTP síncrono.** El ejemplo más
pequeño que demuestra un principio real: cuando los servicios son independientes, la única
forma de validar un dato que "pertenece" al otro es preguntárselo por la red.

```
┌─────────────┐       ┌──────────────────────────────────┐       ┌────────────────┐
│             │       │                                  │       │                │
│   Cliente   │──────▶│   task-service   :8001           │──────▶│ user-service   │
│  curl /     │ POST  │   db_tasks.sqlite                │  GET  │ :8000          │
│  Postman/UI │◀──────│   NO tiene la tabla users        │◀──────│ db_users.sqlite│
│             │ 201   │   API REST + UI web Blade        │ 200   │ headless       │
└─────────────┘       └──────────────────────────────────┘  404  └────────────────┘
```

Cuando creas una tarea, el **task-service no sabe** si el `user_id` existe — ese dato vive en
otra base de datos, en otro proceso, detrás de otro socket. Así que lo pregunta. Si el
user-service responde `200`, guarda; si responde `404`, se niega; si está caído, devuelve `503`
y no escribe nada.

---

## Qué demuestra

- ✅ Aislamiento real de datos: **dos bases de datos, cero FKs cruzadas**
- ✅ Comunicación **síncrona por HTTP** entre servicios con `Http::get()`
- ✅ Un **cliente dedicado** (`UserServiceClient`) que encapsula la frontera de red
- ✅ **Errores tipados** (`UserServiceUnavailableException` / `UserServiceErrorException`) renderizados en un solo lugar
- ✅ **CRUD completo**: API REST + interfaz web Blade, ambos con la misma lógica
- ✅ **Degradación elegante**: si el user-service cae, la UI sigue funcionando
- ✅ Tests con `Http::fake()` — sin levantar el otro servicio

## Requisitos

- PHP **8.3+** (probado en 8.4)
- Composer 2
- Extensión `pdo_sqlite` (viene por defecto)
- Laravel **13** en ambos proyectos

---

## Instalación

```bash
git clone <este-repo> demo-microservicios
cd demo-microservicios

# --- user-service ---
cd user-service
composer install
cp .env.example .env
php artisan key:generate
touch database/db_users.sqlite          # la BD es un archivo SQLite
php artisan migrate:fresh --seed
php artisan serve --port=8000

# --- task-service (en otra terminal) ---
cd task-service
composer install
cp .env.example .env
php artisan key:generate
touch database/db_tasks.sqlite
php artisan migrate:fresh --seed
php artisan serve --port=8001
```

Luego abre **<http://127.0.0.1:8001/tasks>**.

> Las rutas de base de datos en `.env.example` son **relativas** (`database/db_users.sqlite`)
> para que el repo funcione en cualquier máquina. El archivo SQLite debe existir: `touch`
> o `migrate:fresh`.

Datos que crea el seeder: **Ana Torres** (id 1) y **Luis Ramirez** (id 2).

---

## Los dos servicios

| | user-service | task-service |
|---|---|---|
| Puerto | `http://127.0.0.1:8000` | `http://127.0.0.1:8001` |
| Base de datos | `database/db_users.sqlite` | `database/db_tasks.sqlite` |
| Tablas | `users` | `tasks`, `cache`, `jobs` |
| ¿Conoce usuarios? | **Sí**, es el dueño | **No** — solo guarda el `user_id` |
| ¿Conoce tareas? | **No** | **Sí**, es el dueño |
| Interfaz | Ninguna (headless) | API REST **+ UI web** |
| Tests | 5 | 23 (68 asserts) |

---

## API

### user-service

| Método | Ruta | Respuesta |
|---|---|---|
| `GET` | `/api/users` | Lista paginada (JSON) |
| `GET` | `/api/users/{id}` | `200` con el modelo · `404` `{"error":"Usuario no encontrado"}` |

### task-service

| Método | Ruta | UI web equivalent |
|---|---|---|
| `GET` | `/api/tasks` | `/tasks` |
| `POST` | `/api/tasks` | `/tasks/create` |
| `GET` | `/api/tasks/{id}` | `/tasks/{id}` |
| `PUT` | `/api/tasks/{id}` | `/tasks/{id}/edit` |
| `DELETE` | `/api/tasks/{id}` | `/tasks/{id}` |

### Matriz de respuestas

| Situación | user-service | task-service | ¿Se guarda? |
|---|---|---|---|
| Payload inválido | — | `422` | No |
| `user_id` existe | `200` | `201` + tarea | **Sí** |
| `user_id` no existe | `404` | `422` "El usuario no existe en el sistema" | No |
| user-service devuelve `500` | `500` | `502` | No |
| user-service apagado / timeout | — | `503` | No |

En los cuatro casos de fallo **no se escribe nada** en `db_tasks`. Ese es el invariante del flujo.

---

## Pruebas rápidas con cURL

```bash
# El usuario existe
curl -i http://127.0.0.1:8000/api/users/1
# {"id":1,"name":"Ana Torres",...}                                  [200]

# El usuario no existe
curl -i http://127.0.0.1:8000/api/users/999
# {"error":"Usuario no encontrado"}                                [404]

# Crear tarea -> el task-service consulta al user-service internamente
curl -i -X POST http://127.0.0.1:8001/api/tasks \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"title":"Comprar el almuerzo","user_id":2}'
# {"title":"Comprar el almuerzo","user_id":2,"status":"pending","id":4}  [201]

# Usuario inválido -> NO se guarda nada
curl -i -X POST http://127.0.0.1:8001/api/tasks \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"title":"Tarea huérfana","user_id":77}'
# {"error":"El usuario no existe en el sistema"}                    [422]
```

**La prueba que demuestra que es un sistema distribuido:** detén el user-service
(`Ctrl+C`) y repite el `POST`. El task-service responde `503` en milisegundos. Ningún código
dentro del task-service pudo evitarlo: la información no está en su proceso.

Y a la inversa: borra `user-service/` completo y el task-service **sigue funcionando** para
listar, editar y borrar tareas. Solo pierde la creación de tareas nuevas.

### Postman

```json
{
  "user_service": "http://127.0.0.1:8000",
  "task_service": "http://127.0.0.1:8001"
}
```

Requests: `GET {{user_service}}/api/users/1` · `POST {{task_service}}/api/tasks`
con body `{"title":"Tarea","user_id":2}`.

---

## Tests

```bash
cd user-service && vendor/bin/pest --compact   # 5 tests
cd task-service && vendor/bin/pest --compact   # 23 tests
```

Ninguno de los dos servicios necesita al otro corriendo: `Http::fake()` simula el `200`, el
`404`, el `500` y la conexión caída. La suite es rápida y determinista, pero el contrato queda
verificado.

---

## Decisiones de diseño (y por qué)

**`tasks.user_id` no es una llave foránea.** Es un `unsignedBigInteger` indexado. Una FK física
exigiría que ambas tablas vivieran en el mismo motor de base de datos — que es exactamente lo que
rompe el aislamiento. La integridad se valida por HTTP.

**Toda la frontera de red vive en `UserServiceClient`.** El URL base, el timeout, el manejo de
errores de red y la forma del JSON. Los controllers no saben nada del tema. Es el patrón de un
API Gateway: tratar al otro microservicio como si fuera una función local.

**Dos excepciones de dominio, renderizadas en un solo lugar.** `bootstrap/app.php` las traduce
a JSON 503/502 en la API y a una página 503 en la UI. Ningún endpoint puede olvidarse de
manejarlas.

**`update` solo revalida si el `user_id` cambió.** Editar un título no gasta una llamada de red.

**La UI degrada en vez de romperse.** Si el user-service está caído, el formulario cae a un input
numérico con un banner, y la vista de detalle muestra la tarea sin el nombre del propietario.

**`Route::apiResource('tasks')->names('api.tasks')`.** Sin el prefijo, la API registra
`tasks.index` antes que la web y `route('tasks.index')` termina apuntando a `/api/tasks`. Es un
clásico de Laravel.

**Cero paso de build.** Las vistas llevan su CSS inline y no usan `@vite`. Funciona sin
`npm install`.

---

## Estructura

```
demo-microservicios/
├── .gitignore
├── README.md
├── FLUJO-MICROSERVICIOS.md           ← documentación completa (con roadmap)
├── FLUJO-MICROSERVICIOS.html         ← la misma, con formato
├── FLUJO-MICROSERVICIOS-PLAIN.html   ← la misma, HTML sin estilos
│
├── user-service/                     ← :8000 · db_users.sqlite
│   ├── app/Http/Controllers/UserController.php
│   ├── database/migrations/…_create_users_table.php
│   ├── routes/api.php
│   └── tests/Feature/UserControllerTest.php
│
└── task-service/                     ← :8001 · db_tasks.sqlite
    ├── app/Services/UserServiceClient.php        ← la frontera de red
    ├── app/Exceptions/UserService*.php           ← 503 / 502 tipados
    ├── app/Http/Controllers/TaskController.php    ← UI web
    ├── app/Http/Controllers/Api/TaskController.php ← API REST
    ├── app/Http/Requests/Store|UpdateTaskRequest.php
    ├── database/migrations/…_create_tasks_table.php
    ├── resources/views/{layouts,tasks,errors}/
    ├── config/services.php                        ← bloque user_service
    └── tests/Feature/{TaskApiTest,TaskUiTest}.php
```

## Configuración

`task-service/.env`:

```dotenv
USER_SERVICE_URL=http://127.0.0.1:8000
USER_SERVICE_TIMEOUT=3
```

Nada de URLs hardcodeadas en el código: si el user-service cambia de host o puerto, solo tocas
el `.env`. El `timeout` evita que una caída del peer bloquee al task-service indefinidamente.

---

## Limitaciones honestas

- **Sin circuit breaker**: cada petición caída espera los 3 s completos del timeout.
- **Doble escritura sin transacción distribuida**: si el user-service responde `200` y luego falla
  el insert, la validación "pasó" pero no hay tarea.
- **Acoplamiento al contrato**: si el user-service cambia la forma del `404`, el task-service se
  entera al instante.
- **Sin autenticación**: cualquiera puede crear tareas. En localhost no importa.
- **SQLite** aguanta el demo, pero no es un motor de producción con varios escribiendo a la vez.

## Y hacia dónde va

El roadmap completo, con el razonamiento, está en
[`FLUJO-MICROSERVICIOS.md`](FLUJO-MICROSERVICIOS.md#parte-2--qué-sigue-después):

**RabbitMQ** (de síncrono a asíncrono y el trade-off entre garantía y disponibilidad) ·
**JWT vs Sanctum vs mTLS** · **Laravel Octane** y cuándo *no* usarlo · por qué **Lumen** quedó
atrás · observabilidad con OpenTelemetry · circuit breaker e idempotencia · testing de contratos
· despliegue en contenedores.

En resumen: **métricas → autenticación → circuit breaker → API versioning → contratos →
RabbitMQ → Docker → Octane.** Los primeros tres cuestan horas y evitan incidentes; el último
puede esperar un año.

---

## Documentación

| Archivo | Formato |
|---|---|
| [`FLUJO-MICROSERVICIOS.md`](FLUJO-MICROSERVICIOS.md) | Markdown — la referencia completa |
| [`FLUJO-MICROSERVICIOS.html`](FLUJO-MICROSERVICIOS.html) | HTML con estilos, diagrama y modo claro/oscuro |
| [`FLUJO-MICROSERVICIOS-PLAIN.html`](FLUJO-MICROSERVICIOS-PLAIN.html) | HTML sin estilos — se abre y se imprime bien |

---

## Aprende Laravel a fondo

Si quieres ver el **curso completo de Laravel** con este mismo enfoque práctico — microservicios
reales, APIs, colas, testing y despliegue, paso a paso y sin atajos:

**<https://www.desarrollolibre.net/blog/laravel/curso-laravel>**

---

*user-service `:8000` · db_users.sqlite — task-service `:8001` · db_tasks.sqlite*