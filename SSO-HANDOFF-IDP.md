# SSO con `user-service` como Identity Provider

> Documento de arquitectura del repo `demo-microservicios`. Describe cómo el
> `user-service` pasó de ser un servicio "headless" a ser el **único IdP** del
> sistema, y cómo `task-service` y `billing-service` se convirtieron en
> **relying parties (RP)** puros.

## Índice

- [1. El problema](#1-el-problema)
- [2. La regla que ordena todo](#2-la-regla-que-ordena-todo)
- [3. El flujo completo](#3-el-flujo-completo)
- [4. Seguridad del código](#4-seguridad-del-código)
- [5. Endpoints del IdP](#5-endpoints-del-idp)
- [6. Qué cambió en cada componente](#6-qué-cambió-en-cada-componente)
- [7. Single logout](#7-single-logout)
- [8. Decisiones de diseño](#8-decisiones-de-diseño)
- [9. Verificación](#9-verificación)
- [10. Limitaciones honestas](#10-limitaciones-honestas)

## 1. El problema

Antes había tres servicios, pero **tres logins**:

- `task-service` tenía `AuthController`, vista `auth/login`, ruta `/login` y
  `TokenSession::login()` pidiendo credenciales a `user-service`.
- `billing-service` tenía exactamente lo mismo, duplicado.
- `user-service` era el dueño de la identidad, pero **no tenía pantalla de login**.

Consecuencias:

1. El formulario de login estaba duplicado en cada RP.
2. Entrar a `billing` desde `task` obligaba a teclear la contraseña otra vez.
3. Cerrar sesión en un servicio no cerraba nada en el otro.
4. Cada RP era, de facto, un segundo lugar donde se manejan credenciales.

## 2. La regla que ordena todo

> **El login y los datos de usuario viven SOLO en `user-service`. Ningún RP tiene
> formulario de login, rutas `/login`, modelo `User`, tabla `users` ni toca la
> base de datos de identidad.**

Los RPs solo pueden:

- Redirigir al IdP.
- Recibir un `code` de un solo uso.
- Canjearlo por un token propio.
- Guardar ese token en su sesión local.
- Validar el token contra `/api/user` en cada request.

## 3. El flujo completo

```text
Navegador                 task-service (RP)              user-service (IdP)
    │                          │                                │
    │ GET /tasks (sin sesión)  │                                │
    ├─────────────────────────▶│ middleware auth.user-service   │
    │ 302 /sso/start           │                                │
    │◀─────────────────────────┤                                │
    │ GET /sso/start           │                                │
    ├─────────────────────────▶│ SsoStartController             │
    │ 302 /authorize?client=task&redirect_uri=...                │
    │◀─────────────────────────┤                                │
    │ GET /authorize...        │                                │
    ├──────────────────────────────────────────────────────────▶│
    │                          │            ¿sesión SSO?        │
    │                          │            NO → 302 /login      │
    │ 302 /login?client=task&redirect_uri=...                    │
    │◀──────────────────────────────────────────────────────────┤
    │ GET /login (formulario)  │                                │
    ├──────────────────────────────────────────────────────────▶│
    │ POST /login (email+pass) │                                │
    ├──────────────────────────────────────────────────────────▶│ Auth::attempt
    │                          │                                │ emite code (60 s)
    │ 302 redirect_uri?code=XYZ│                                │
    │◀──────────────────────────────────────────────────────────┤
    │ GET /sso/callback?code=XYZ                                │
    ├─────────────────────────▶│ SsoCallbackController          │
    │                          │ POST /api/sso/exchange         │
    │                          ├───────────────────────────────▶│ valida code+client
    │                          │                                │ crea token del RP
    │                          │◀───────────────────────────────┤ 201 {token,user}
    │                          │ loginWithToken() → sesión local│
    │ 302 /tasks               │                                │
    │◀─────────────────────────┤                                │
```

Puntos clave:

- **El token nunca viaja por la URL.** Por la URL solo va el `code`.
- El canje (`/api/sso/exchange`) es **backchannel**: servidor a servidor.
- Cada RP obtiene **su propio token** (`device_name` = `task-sso` / `billing-sso`),
  así su revocación es independiente.
- Si el navegador ya tiene sesión en el IdP, el paso del formulario se salta:
  `/authorize` emite el código directamente y el RP entra sin pedir contraseña.
  Eso es lo que elimina el re-login entre `task` y `billing`.

## 4. Seguridad del código

| Medida | Implementación |
| --- | --- |
| Un solo uso | `used_at` se marca en el canje; un segundo intento responde `400`. |
| Caducidad corta | `sso.ticket_ttl` = 60 s. |
| Sin texto plano en BD | Se guarda `sha256(code)` en `sso_tickets.code_hash` (unique). |
| Cliente validado | El `client` debe existir en `config/sso.php`. |
| `redirect_uri` validado | Whitelist exacta por cliente (evita open redirect). |
| Código de un solo RP | Si el `client` del ticket no coincide con el del canje, `400`. |
| Rotación | Emitir un código nuevo borra los pendientes del mismo usuario+cliente. |
| Sesión regenerada | `TokenSession::loginWithToken()` regenera el id de sesión. |

## 5. Endpoints del IdP

| Método | Ruta | Auth | Descripción |
| --- | --- | --- | --- |
| `GET` | `/login` | guest | Único formulario de login del sistema. |
| `POST` | `/login` | `throttle:6,1` | Autentica y, si trae `client` + `redirect_uri`, redirige con `code`. |
| `GET` | `/authorize` | sesión web | Punto de entrada SSO. Sin sesión → `/login`; con sesión → emite `code`. |
| `GET`/`POST` | `/logout` | — | Cierra la sesión central y redirige al `redirect_uri`. |
| `POST` | `/api/sso/exchange` | pública | Canjea `{code, client}` por un token del RP. |
| `POST` | `/api/tokens` | pública | Login para clientes no navegador (CLI, Postman). |
| `GET` | `/api/user` | Bearer | Introspección de token. |
| `DELETE` | `/api/tokens` | Bearer | Revoca el token. |

## 6. Qué cambió en cada componente

### 6.1 `user-service` (IdP)

- `config/sso.php`: whitelist de `clients` con sus `redirect_uri` y `ticket_ttl`.
- `app/Services/SsoTicketIssuer.php`: valida cliente y destino, rota códigos
  pendientes y devuelve el código en claro (la única vez que existe).
- `app/Models/SsoTicket.php` + migración: `code_hash`, `user_id`, `client`,
  `expires_at`, `used_at`. Ya **no** tiene `token_id`: el ticket no está atado al
  token de origen, solo al usuario y al cliente.
- `WebLoginController`: `show`, `login`, `logout`.
- `SsoController`: `authorize` (web) y `exchange` (API).
- Se eliminó `POST /api/sso/authorize` (el hand-off server-to-server antiguo).
- La tabla `users` recuperó `remember_token`, necesario para
  `Auth::attempt(..., remember: true)`.

### 6.2 Paquete `demo/microservices-user-service-client`

- `IdentityProvider::exchangeCode(string $code, string $client): ?array`.
  Se eliminó `authorizeSso()`.
- `UserServiceClient::exchangeCode()` devuelve `null` para `400/401/404/422` y
  lanza excepción para el resto.
- `TokenSession`: se eliminó `login()`; ahora `loginWithToken(Request, string)` y
  `logout(Request)`.
- Controllers nuevos: `SsoStartController`, `SsoCallbackController`,
  `SsoLogoutController`.
- Config: `client`, `callback_url`, `sso_start_route` (adiós a `login_route`).
- El middleware redirige a `sso_start_route`; si no está configurada, responde
  `401` JSON (modo API pura).

### 6.3 `task-service` y `billing-service` (RPs)

Borrado en ambos:

- `app/Http/Controllers/AuthController.php`
- `app/Models/User.php`, `database/factories/UserFactory.php`
- migración de `users` (y `config/auth.php`)
- `resources/views/auth/`
- en billing, su antiguo `SsoCallbackController`

Rutas nuevas en ambos:

```php
Route::get('/sso/start', SsoStartController::class)->name('sso.start');
Route::get('/sso/callback', SsoCallbackController::class)->name('sso.callback');
Route::post('/logout', SsoLogoutController::class)->name('logout');
```

El nav enlaza al otro servicio con `config('services.billing.url')` /
`config('services.task.url')`, y "Iniciar sesión" apunta a `route('sso.start')`.

## 7. Single logout

El RP no intenta cerrar sesiones ajenas:

```text
POST rp/logout
  → TokenSession::logout() revoca SU token y destruye SU sesión
  → redirect 302 {idp}/logout?redirect_uri={rp}/
  → el IdP destruye la sesión SSO central
  → vuelve al RP, que ya no tiene sesión
```

La sesión central es la que manda. Si el usuario vuelve a otro RP, será ese RP
quien descubra que su token ya no vale (o que el IdP ya no tiene sesión) y
reinicie el flujo SSO.

## 8. Decisiones de diseño

- **El navegador va al IdP, no el servidor.** A diferencia del hand-off anterior
  (task pedía el ticket server-to-server con su token), ahora el RP solo redirige.
  Es el flujo Authorization Code real y permite que el IdP reutilice su sesión.
- **Un token por RP.** Revocar billing no afecta a task.
- **El código no está atado al token de origen.** Atarlo impedía el flujo cuando
  el usuario llega directo al IdP sin token previo de ningún RP.
- **Whitelist en configuración.** Para la demo es suficiente y explícita; en
  producción sería un registro de clientes.
- **`POST /api/tokens` se conserva.** Es el login de clientes que no son
  navegador, y ya estaba cubierto por rate limit y tests.

## 9. Verificación

```bash
cd user-service && php artisan test --compact      # 53 tests
cd task-service && php artisan test --compact      # 67 tests
cd billing-service && php artisan test --compact   # 9 tests
```

E2E manual (los tres levantados en 8000/8001/8002):

1. `GET :8001/tasks` → 302 `/sso/start` → 302 `:8000/authorize?client=task...`
2. Sin sesión en el IdP → 302 `/login?...`; el formulario es el único del sistema.
3. Login correcto → 302 `:8001/sso/callback?code=...` → 302 `/tasks` → `200`.
4. `GET :8002/facturas` en el mismo navegador → pasa por SSO sin pedir contraseña.
5. `POST :8002/logout` → 302 al `/logout` del IdP → vuelve sin sesión.

## 10. Limitaciones honestas

| Limitación | Estado | Camino |
| --- | --- | --- |
| Single logout no federado | Consciente | OIDC front/back-channel logout. |
| Sin `state`/`nonce`/PKCE | Consciente | Añadir en un flujo OAuth real. |
| Whitelist en config | Consciente | Registro de clientes en BD. |
| Sin refresh tokens | Consciente | Rotación de tokens por RP. |
| Sin revocación global | Consciente | Tabla de sesiones SSO + endpoint de revocación. |
