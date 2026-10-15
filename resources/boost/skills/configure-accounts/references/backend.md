# Backend: middleware, roles y rutas

## Where to Find It

Search with `search-docs`:
- `"accounts middleware"` for `accounts.access`, `accounts.activity` and `accounts.step-up`
- `"accounts roles"` for `HasAccountsRoles` and `Roles`

Source of truth: `README.md`, `config/accounts.php`, `routes/web.php` and `src/Http/` in the package.

## What to Watch For

### `accounts.access` valida con Accounts como máximo cada 60 s

Revocado o caducado → cierra la sesión local y manda a login. Accounts caído → 503 recuperable (`Retry-After`) y nunca se repite una mutación. `accounts.access:lenient` deja pasar a quien entró por otro método. No amplíes `validation_seconds` sin medirlo.

### `accounts.activity` y `accounts.step-up`

- `accounts.activity` reporta actividad como mucho cada 15 s; ignora polling, prefetch y respuestas con error.
- `accounts.step-up:acr=urn:accounts:acr:phr,max_age=300` exige autenticación más fuerte. Niveles: `urn:accounts:acr:pwd` < `mfa` < `phr` (passkey). A clientes JSON responde 401 con `code: accounts_step_up_required`.

### Roles → Gates

`Roles::OWNER|ADMIN|MEMBER`. Los asigna Accounts, se copian al login y se refrescan en ≤60 s. Sin `roles` en la respuesta, la persona queda sin roles.

```php
use LuisML\AccountsClient\Roles;

Gate::define('manage-servers', fn (User $user): bool => $user->hasAccountsRole(Roles::ADMIN));
Gate::define('viewHorizon', fn ($user = null) => $user !== null && $user->hasAccountsRole(Roles::ADMIN));
```

Protege después la ruta con `can:manage-servers` junto a los middleware de Accounts. Los permisos finos siguen siendo de cada app.

### Props compartidas de Inertia

En `HandleInertiaRequests::share`: `'accountsUrl' => accounts_account_url()` (`{ACCOUNTS_ISSUER}/account` o `ACCOUNTS_ACCOUNT_URL`) y `'auth' => ['user' => $request->user()]`.

### Rutas que registra la librería (prefijo `accounts.routes.prefix`, default `auth/accounts`)

| Nombre | Método y ruta | Notas |
|---|---|---|
| `accounts.login` | `GET /auth/accounts/redirect` | Acepta `login_hint`, `prompt` (`login`/`consent`/`select_account`), `acr_values`, `max_age`. |
| `accounts.callback` | `GET /auth/accounts/callback` | Retorno OAuth; no enlazar. |
| `accounts.logout` | `POST /auth/accounts/logout` | Cierra la sesión local siempre y revoca; con `global_logout` (default `true`) también la central. |
| `accounts.switch` | `POST /auth/accounts/switch` | Cierra solo la sesión local y redirige a `accounts.login` con `prompt=select_account`. Acepta `login_hint`. Solo está en `AccountsSwitchController`, no en el README. |
| `accounts.signed-out` | `GET /auth/accounts/signed-out` | Estados `done`, `local`, `central_pending`. |
| `accounts.backchannel-logout` | `POST /auth/accounts/backchannel-logout` | Server-to-server, sin cookies ni CSRF; no enlazar. |
| `login` | `GET /login` | Envía al invitado a Accounts; si vuelve en <30 s muestra botón manual (anti-bucle). Desactivable con `accounts.routes.login=false`. |

### `login`, `logout` y `switch` responden 409 + `X-Inertia-Location` a peticiones Inertia

Es el trait `LeavesTheApplication`: Inertia hace una visita completa en vez de seguir un redirect cross-origin por XHR. Por eso funcionan con `<Link as="button">`.

### Cierre de sesión central

- `ACCOUNTS_GLOBAL_LOGOUT=false` deja el cierre solo local.
- `ACCOUNTS_POST_LOGOUT_REDIRECT_URI` debe estar registrada en `post_logout_redirect_uris` de la app en Accounts.
- Back-channel: registra `https://tu-app/auth/accounts/backchannel-logout`; requiere caché compartida (no `array`).
- Los reintentos de revocación necesitan cola persistente y worker; con `sync` no hay reintentos durables.
