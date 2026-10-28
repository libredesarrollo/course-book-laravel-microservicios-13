# SSO con hand-off de código de un solo uso (user-service como IdP)

> Documento para la demo del repo `demo-microservicios`. Describe cómo pasar de "sesiones por cookie separadas" a un **SSO real en miniatura** con `user-service` actuando como **Identity Provider (IdP)**.

## Índice

- [1. El problema hoy](#1-el-problema-hoy)
- [2. Cómo se hace en microservicios de verdad](#2-cómo-se-hace-en-microservicios-de-verdad)
- [3. Nuestra propuesta: hand-off de código de un solo uso](#3-nuestra-propuesta-hand-off-de-código-de-un-solo-uso)
- [4. Seguridad del ticket](#4-seguridad-del-ticket)
- [5. Cambios necesarios por componente](#5-cambios-necesarios-por-componente)
- [6. Fases de implementación](#6-fases-de-implementación)
- [7. Decisiones de diseño](#7-decisiones-de-diseño)
- [8. Limitaciones honestas y siguientes pasos](#8-limitaciones-honestas-y-siguientes-pasos)
- [9. Verificación](#9-verificación)

## 1. El problema hoy

En el estado actual tenemos tres microservicios:

- `user-service` (8000): dueño de identidad, emite y revoca `api_tokens`.
- `task-service` (8001): UI web + API de tareas. Usa el paquete `demo/microservices-user-service-client`.
- `billing-service` (8002): UI web + API de facturas. Usa el mismo paquete.

**Síntoma:** al hacer clic en "Facturas" desde `task-service` hay que **volver a iniciar sesión** en `billing-service`. Las credenciales son las mismas (mismo `user-service`) y el diseño es idéntico, pero las sesiones son **completamente independientes**.

**Causa:** cada aplicación Laravel guarda su propia cookie de sesión (`task-service-session`, `billing-service-session`) en su propio almacén (`storage/framework/sessions`). El `user-service` valida el token por HTTP en cada petición, pero **no comparte la sesión entre servicios**.

## 2. Cómo se hace en microservicios de verdad

| Patrón | Cómo funciona | Cuándo usarlo | Inconvenientes |
| --- | --- | --- | --- |
| **IdP central (OIDC/OAuth2)** | Hay un **Authorization Server** central (Keycloak, Auth0, Entra ID...). Este tiene su propia cookie de **sesión SSO**. Cuando un Relying Party (RP) necesita autenticar, redirige al IdP. Si ya hay sesión SSO, el IdP **no pide contraseña** y devuelve un `code` de un solo uso. Cada RP mantiene su propia sesión local intercambiando ese código. | **Estándar para UIs web**. Es lo que resuelve el re-login. | Requiere infra de IdP o implementarlo siguiendo el RFC. |
| **Cookie compartida** | Todos los servicios bajo el **mismo dominio padre** (`.app.com`) comparten una cookie con el mismo nombre. Todos leen/escriben en el **mismo almacén de sesiones** (Redis compartido). | Raro en microservicios puros. Se ve más en monolitos desplegados por capas. | Acopla todos los servicios al mismo store y al mismo esquema de sesión. Difícil de escalar/desplegar independientemente. |
| **Edge auth / BFF** | Un **Gateway** (Kong, Envoy) o **BFF** autentica una vez y **inyecta la identidad en cabeceras internas** (`X-User-Id`, `X-User-Email`). Los microservicios **confían** en esas cabeceras. | Muy común para APIs. Óptimo cuando el UI pasa por un único punto de entrada. | Los servicios ya no validan tokens por sí mismos: confían en la red interna. El gateway se convierte en punto crítico. |

**La clave:** En el patrón OIDC real, **el login central NO reemplaza las sesiones de cada app. Cada servicio sigue teniendo su propia sesión local**. Lo que se elimina es **volver a teclear la contraseña** gracias a la cookie de sesión del IdP.

## 3. Nuestra propuesta: hand-off de código de un solo uso

Vamos a simular el **flujo Authorization Code** con lo que ya tenemos. El `user-service` pasa a ser un **IdP mínimo**.

### Flujo completo

```text
1. Ana logueada en task-service (tiene token en su sesión)
   │
2. Clica "Facturas" → GET task-service/sso/bridge
   │  (task-service usa el token de SU sesión para pedir un ticket)
   ▼
3. POST user-service/sso/authorize  ──►  genera sso_ticket (code hash + ttl 60s + single-use)
   │                                    devuelve {"code": "..."}
   ▼
4. task-service redirige a billing-service/sso/callback?code=XYZ
   │  (el token NUNCA viaja por URL. Solo el código de un solo uso)
   ▼
5. billing-service llama POST user-service/api/sso/exchange {code: XYZ}
   │  (backchannel, sin sesión de billing)
   ▼
6. user-service valida: código correcto, no usado, no expirado, token origen válido
   │  emite un TOKEN NUEVO para billing (device_name: "billing-sso")
   ▼
7. billing recibe {"token": "...", "user": {...}} 
   │  TokenSession::login() guarda el token en SESIÓN PROPIA de billing
   ▼
8. Redirige a /facturas → autenticado SIN pedir contraseña
```

### ¿Por qué por backchannel (server-to-server)?

- **El código nunca aparece en logs del navegador** si lo pasáramos por rutas públicas de forma descuidada, pero lo importante: **el exchange se hace desde el servidor de billing al user-service**. Nadie expone el token de task-service.
- **Un solo uso**: en cuanto se canjea, `used_at` se marca. Si alguien reutiliza el mismo código → rechazado.
- **Corto tiempo de vida**: 60 segundos. Aunque se filtre, caduca muy rápido.

## 4. Seguridad del ticket

El ticket debe tratarse casi como un **authorization code** real:

| Medida | Razón |
| --- | --- |
| **32 bytes aleatorios (`random_bytes`)** | Inadivinable. |
| **Hash SHA-256 en BD** | Igual que los `api_tokens`. Si la BD se filtra, no se obtienen los códigos en claro. |
| **TTL de 60 segundos** | Ventana mínima para el hand-off. |
| **Un solo uso (`used_at` nullable)** | El código se invalida al primer intercambio. |
| **Ligado a usuario** | `user_id` + `token_id` del token que lo generó. |
| **`client` binding** | `client = 'billing'`. El código solo sirve para ese Relying Party. |
| **Backchannel exchange** | El canje se hace servidor-servidor. billing nunca reenvía la cookie/sesión de task. |
| **Auditoría** | Registrar `sso_ticket_issued` y `sso_token_exchanged` con `request_id` del paquete de observabilidad. |

## 5. Cambios necesarios por componente

### 5.1 `packages/microservices-user-service-client` (el paquete)

Añadimos soporte para el intercambio sin romper lo existente:

- **Contrato:** `Demo\Microservices\UserServiceClient\Contracts\IdentityProvider` → nuevo método `public function exchangeCode(string $code): ?array;`
- **Implementación:** `UserServiceClient::exchangeCode()` → `POST {base_url}/api/sso/exchange` con `{'code' => $code}`. Reutiliza `traceHeaders()` para propagar `X-Request-Id`. Clasifica errores igual que `authenticate()`.
- **Excepciones:** Podemos reutilizar `UserServiceErrorException` / `UserServiceUnavailableException`. Opcional: `SsoCodeInvalidException` / `SsoCodeExpiredException` si queremos granularidad.
- **Tests del paquete:** cubrir `exchangeCode()` con `Http::fake()`.

### 5.2 `user-service` (el IdP)

- **Migración `sso_tickets`:**
  ```sql
  id, code_hash (unique), user_id, token_id, client, expires_at, used_at, created_at, updated_at
  ```
- **Autenticación para emitir tickets:** necesitamos saber **quién pide el ticket**. El servicio que solicita (task-service) lo hará con el token del usuario en su sesión. El `user-service` ya tiene `AuthenticateApiToken` para validar tokens: podemos reutilizarlo o crear un endpoint autenticado.
- **Rutas:**
  - `POST /sso/authorize` (protegida con `auth.api-token`): recibe `redirect` y `client`. Genera código, guarda hash, devuelve el código en claro **una sola vez**.
  - `POST /api/sso/exchange` (pública, pero con código de un solo uso + TTL): valida código sin requerir `Authorization: Bearer`, marca `used_at`, crea un **token nuevo** para ese usuario con `device_name` acorde al cliente (`billing-sso`) y devuelve `{token, token_type, expires_at, user}`.
- **Seguridad:** al revocar el `api_token` original, conviene **invalidar tickets no usados** asociados a ese `token_id` (best-effort).

### 5.3 `task-service` (quien inicia el hand-off)

- **Controlador web:** `App\Http\Controllers\SsoBridgeController` con `GET /sso/bridge`.
  - Requiere sesión válida (ya protegido por `auth.user-service`).
  - Lee el token de sesión (`TokenSession::SESSION_KEY`).
  - Llama al `user-service` (o directamente a la lógica) para obtener un ticket vía backchannel.
  - Redirige a `BILLING_SERVICE_URL.'/sso/callback?code='.$code`.
- **Rutas:** `Route::middleware('auth.user-service')->get('/sso/bridge', ...)`
- **Vista/nav:** cambiar el enlace "Facturas" para apuntar a `route('sso.bridge')` en lugar de `env('BILLING_SERVICE_URL').'/facturas'` directamente.

### 5.4 `billing-service` (Relying Party)

- **Controlador web:** `App\Http\Controllers\SsoCallbackController` con `GET /sso/callback`.
  - Lee `?code=...`. Si falta → 400/redirect a login.
  - Llama `$identityProvider->exchangeCode($code)`. Si devuelve `null` o lanza excepción → muestra error y manda a `/login`.
  - Usa `$tokenSession->loginFromToken()` o directamente guarda el token en sesión y **regenera sesión** (importante contra session fixation).
  - Redirige a `/facturas` con sesión ya iniciada.
- **Rutas:** `GET /sso/callback` (público, el código es la credencial de un solo uso)
- **Paquete:** el `TokenSession` actual tiene `login(Request, array $credentials, string $deviceName)`. Añadiremos un método `loginWithToken(Request $request, string $token): void` para el caso SSO (ya tenemos el token, no pedimos credenciales al user-service).

## 6. Fases de implementación

| Fase | Tarea | Notas |
| --- | --- | --- |
| **Fase 0** | Crear este documento | Hecho. |
| **Fase 1** | Ampliar paquete `user-service-client` | `exchangeCode()`, contrato, implementación HTTP, `loginWithToken()` en `TokenSession`. Pint + tests del paquete. |
| **Fase 2** | Migración + rutas + lógica en `user-service` | `sso_tickets`, `POST /sso/authorize`, `POST /api/sso/exchange`. Crear token nuevo con `device_name='billing-sso'`. Invalidar tickets al revocar token. Tests Pest. |
| **Fase 3** | `task-service` bridge | `SsoBridgeController`, ruta protegida, cambiar enlace del nav. Test: sin sesión → redirigido al login; con sesión → redirige al callback de billing. |
| **Fase 4** | `billing-service` callback | `SsoCallbackController`, ruta `/sso/callback`, integración con paquete, login silencioso. Tests: código válido, código reutilizado, código expirado, código inválido. |
| **Fase 5** | Ajustes UI | El "re-login" desaparece: desde task pulsas Facturas y entras directo. Mantener botón "Cerrar sesión" (cierra solo la sesión local del servicio; el token puede seguir vivo hasta expirar/revocarse). Documentar la limitación restante (no hay single logout). |
| **Fase 6** | Verificación completa | Pint en servicios + `packages/`, tests de los 3, E2E con los 3 levantados en 8000/8001/8002, revisar logs con `X-Request-Id`, actualizar `README.md` y `docs/06-por-que-empaquetar-como-usarlo.html` si aplica. Commit atómico. |

## 7. Decisiones de diseño

- **Token nuevo por Relying Party.** En lugar de reutilizar el token de `task-service`, `billing-service` obtiene **su propio token**. Razón: independencia de revocación. Si mañana revoco sesiones de billing, no tengo que tocar task. Es exactamente lo que hace OAuth: **access tokens por cliente**.
- **Mint server-to-server desde task-service.** Hoy `user-service` no tiene cookie de sesión SSO. Pedir el ticket desde el servidor de task (autenticado con su token) es pragmático y mantiene el token fuera del browser. En un despliegue real con IdP, sería el **browser** el que redirigiría al IdP.
- **Código hash en BD.** Mismo criterio que `api_tokens.token` (SHA-256). Defensa en profundidad.
- **TTL corto (60s).** Suficiente para el redirect + intercambio, demasiado corto para ser reutilizable maliciosamente.
- **Backchannel obligatorio para el exchange.** El callback solo entrega el código; el canje es HTTP directo entre billing y user-service.

## 8. Limitaciones honestas y siguientes pasos

| Limitación | Estado | Solución futura |
| --- | --- | --- |
| **Sin cookie SSO en el IdP** | Consciente | Si el `user-service` tuviera su propia sesión web con cookie SSO, el flujo podría ser puramente por redirección browser→IdP sin que task pida el ticket server-to-server. |
| **Sin single logout** | Consciente | Cerrar sesión en task **no** cierra la sesión de billing. Se podría añadir una tabla `sso_sessions` + endpoints de logout entre servicios, o usar OIDC back-channel/front-channel logout. |
| **Sin PKCE** | Consciente | En este mini-flujo con cliente interno (mismo repo) no es crítico, pero en un OAuth real con apps públicas sí es obligatorio. |
| **Sin refresh tokens** | Ya apuntado | Aparece como paso 6 en el roadmap del README. |
| **Sin scopes** | Consciente | `billing` podría tener solo `read:invoices`. Añadir `scope` al ticket si se quiere granularidad. |

**Siguiente paso natural:** Tras implementar esto, pasar a **circuit breaker + reintentos con backoff** (paso 5 del roadmap) para cuando el hand-off falle por caída del `user-service`.

## 9. Verificación

Al terminar la implementación, estos checks deben pasar:

```bash
# Tests
cd user-service && vendor/bin/pest --compact
cd task-service && vendor/bin/pest --compact
cd billing-service && vendor/bin/pest --compact

# Pint
cd task-service && vendor/bin/pint --test ../packages
cd task-service && vendor/bin/pint --test
cd billing-service && vendor/bin/pint --test
cd user-service && vendor/bin/pint --test

# E2E manual
# 1. Login en http://127.0.0.1:8001
# 2. Click "Facturas" → debe ir directo a http://127.0.0.1:8002/facturas SIN pantalla de login
# 3. Comprobar nombre "Ana Torres" y facturas visibles
# 4. Revisar logs user-service: debe aparecer sso_ticket_issued y sso_token_exchanged con mismo X-Request-Id
# 5. Logout en billing NO afecta a task (y viceversa) — documentado como limitación
```

**Éxito:** "Un clic desde Tareas → Facturas sin volver a introducir contraseña". Ese es el objetivo: **sensación de app única, pero arquitectura honesta**.
