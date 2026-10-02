# mevisoft/auth-accounts-luisml

Cliente Laravel de **Accounts LuisML**: «Continuar con LuisML» (OpenID Connect con PKCE) y control central de sesión.
La app deja de gestionar contraseñas; Accounts decide quién entra y cuánto dura la sesión.

Requisitos: PHP ^8.2 y Laravel ^11, ^12 o ^13.

## Instalación

1. Instala el paquete (publicado en Packagist; hoy solo existe `dev-main`, de ahí el `@dev`):

   ```bash
   composer require mevisoft/auth-accounts-luisml:@dev
   ```

2. Prepara el entorno y las migraciones:

   ```bash
   php artisan accounts:install --migrate
   ```

   Añade `ACCOUNTS_*` a `.env` y `.env.example` (sin tocar las que ya existan), ejecuta la migración
   (`accounts_issuer` y `accounts_sub` en `users`, únicas juntas) e imprime el callback exacto que debes registrar.

3. Registra la app en Accounts (`/admin/applications`) con ese callback **exacto** (HTTPS en producción).
   Copia el *client id* y el *secreto* (se muestra una sola vez) a `.env`:

   ```dotenv
   ACCOUNTS_ISSUER=https://accounts.luisml.test
   ACCOUNTS_CLIENT_ID=...
   ACCOUNTS_CLIENT_SECRET=...
   ACCOUNTS_REDIRECT_URI="${APP_URL}/auth/accounts/callback"
   ACCOUNTS_HOME=/dashboard
   ```

   `ACCOUNTS_HOME` es el destino tras entrar cuando no hay otro, y donde aterriza el cierre de sesión.

4. Protege las rutas:

   ```php
   Route::middleware(['auth', 'accounts.access', 'accounts.activity'])->group(function () {
       // rutas protegidas
   });
   ```

5. Ofrece el acceso: un enlace o botón a `route('accounts.login')` (`/auth/accounts/redirect`).
   Con Wayfinder: `import { login } from '@/routes/accounts'` y `login.url()`.
   No uses `accounts.callback` como enlace: es el retorno de OAuth y exige `code` y `state`.

6. Cierre de sesión: haz `POST` a `route('accounts.logout')`. Destruye la sesión local y revoca el acceso en Accounts
   (si no puede confirmarlo, un job lo reintenta). No afecta a otras apps ni a otros navegadores.

## Rutas que registra el paquete

| Nombre | Ruta | Uso |
|---|---|---|
| `accounts.login` | `GET /auth/accounts/redirect` | Inicia el login (PKCE, `state`, `nonce`). |
| `accounts.callback` | `GET /auth/accounts/callback` | Retorno de Accounts. No enlazar. |
| `accounts.logout` | `POST /auth/accounts/logout` | Cierre de sesión con revocación. |

El prefijo se cambia con `accounts.routes.prefix`.

## Middleware

- **`accounts.access`**: toda operación protegida pasa por aquí.
  - Una validación sirve como máximo 60 s (acotada por la caducidad del token y de la sesión central). Pasado ese plazo, Accounts debe confirmar el acceso de nuevo.
  - Si Accounts dice que ya no está activo (revocado, caducado, suspendido), la sesión local se cierra y se envía a iniciar sesión.
  - Si Accounts no responde, la operación se bloquea con un 503 recuperable (`Retry-After`). La sesión local se conserva y **nunca se repite una mutación automáticamente**.
  - `accounts.access:lenient` deja pasar a quien entró por otro método (sin sesión de Accounts), para apps que conservan su login propio. Por defecto es estricto.
- **`accounts.activity`**: informa a Accounts de actividad real, como mucho cada 15 s. Ignora el polling, el prefetch y las respuestas con error.

## Usuarios

La identidad se vincula por `(issuer, sub)`, **nunca por correo**: un usuario local con el mismo correo no se toma ni se enlaza.
Un usuario nuevo se crea al primer login, con el correo ya verificado por Accounts.

Para hacer algo más al crear o vincular (p. ej. un equipo personal), indica tu propio resolver en `config/accounts.php`:

```php
'user_resolver' => App\Actions\Accounts\ResolveAccountsUser::class,
```

Es una clase invocable que recibe `LuisML\AccountsClient\Identity` y devuelve un `Authenticatable` (o `null` para rechazar):

```php
public function __invoke(Identity $identity): ?Authenticatable
{
    $user = ($this->resolveModelUser)($identity); // LuisML\AccountsClient\Actions\ResolveModelUser

    if ($user?->wasRecentlyCreated) {
        // crear equipo personal, asignar rol por defecto, etc.
    }

    return $user;
}
```

Los roles y permisos siguen siendo locales a cada app: entrar con Accounts no concede ningún rol de negocio.

## Quitar el login propio

Para que Accounts sea el único acceso (como «Iniciar sesión con Google»):

- Usa `accounts.access` en modo estricto.
- Vacía las funciones de Fortify (`'features' => []`), haz que el login con contraseña falle siempre
  (`Fortify::authenticateUsing(fn () => null)`) y deja `/login` como página con solo el botón de Accounts.
- Elimina registro, recuperación de contraseña, verificación de correo, 2FA, passkeys y la página de seguridad:
  todo eso vive en Accounts.
- Los usuarios locales existentes no se enlazan solos; solo entran cuentas vinculadas por `(issuer, sub)`.

`php artisan accounts:install` detecta estos restos de autenticación local y los lista (no borra nada por defecto).
Con `--remove-auth` borra las páginas, componentes, controlador, requests y tests de registro, recuperación de
contraseña, verificación de correo, 2FA, passkeys y seguridad, y vacía `features` en `config/fortify.php`; pide
confirmación (`--force` la omite). Un archivo que otro código todavía usa (p. ej. `SecurityController` desde
`routes/settings.php`) **no se borra**: se avisa de quién lo usa para que lo edites antes. Lo demás (el
`FortifyServiceProvider`, las rutas de seguridad, enlaces de registro, el menú de usuario) se imprime como pasos
manuales porque depende de cada app.

## Opciones de `accounts:install`

| Opción | Qué hace |
|---|---|
| *(sin opciones)* | Añade las variables que falten, informa de los restos de login local, de los grupos de rutas con `auth` sin `accounts.access`, de imports del frontend a rutas que ya no existen y de variables vacías. No borra ni reescribe nada salvo `.env`/`.env.example` (añadir variables) y un `import '@inertiajs/core'` en `global.d.ts` si el borrado lo dejó huérfano. |
| `--migrate` | Ejecuta las migraciones. |
| `--protect-routes` | Añade `accounts.access` y `accounts.activity` a cada `middleware([... 'auth' ...])` de `routes/*.php` que no los tenga. Es idempotente. |
| `--remove-auth` | Borra los restos de login local y vacía `features` de Fortify (con confirmación; `--force` la omite). |
| `--test-helper` | Añade a `tests/TestCase.php` un `actingAs()` que abre una sesión de Accounts validada. |
| `--check` | Pide `/.well-known/openid-configuration` al *issuer*, comprueba que su `issuer` coincide con `ACCOUNTS_ISSUER`, que el callback es una URL absoluta y que la tabla de usuarios tiene las columnas. |

Orden recomendado: `accounts:install --migrate --protect-routes --test-helper --remove-auth`, completar a mano lo que imprime
(login, `FortifyServiceProvider`, menú), rellenar `.env` con los datos de la app registrada y terminar con `accounts:install --check`.

## Pruebas en la app

Con el modo estricto, todo `actingAs` necesita una sesión de Accounts validada. Una forma de hacerlo en `tests/TestCase.php`:

```php
public function actingAs(Authenticatable $user, $guard = null): static
{
    return parent::actingAs($user, $guard)->withSession(['accounts.session' => [
        'subject' => 'test-subject',
        'access_token' => Crypt::encryptString('access-token'),
        'refresh_token' => Crypt::encryptString('refresh-token'),
        'access_expires_at' => now()->addHour()->getTimestamp(),
        'validated_until' => now()->addMinute()->getTimestamp(),
        'session_expires_at' => null,
        'last_report_at' => now()->getTimestamp(),
    ]]);
}
```

## Configuración

`php artisan vendor:publish --tag=accounts-config` publica `config/accounts.php`. Valores principales:

| Clave | Por defecto | Notas |
|---|---|---|
| `issuer`, `client_id`, `client_secret`, `redirect` | — | Del entorno (`ACCOUNTS_*`). |
| `home` | `/` | `ACCOUNTS_HOME`. |
| `scopes` | `openid profile email` | |
| `http.connect_timeout` / `http.timeout` | 2 s / 3 s | `ACCOUNTS_CONNECT_TIMEOUT`, `ACCOUNTS_TIMEOUT`. |
| `validation_seconds` | 60 | Plazo máximo de una validación. No ampliar sin medirlo. |
| `activity_interval_seconds` | 15 | |
| `clock_skew_seconds` | 30 | Tolerancia de reloj en los JWT; no extiende el plazo de 60 s. |
| `users_table`, `user_model`, `user_resolver` | `users`, `App\Models\User`, `ResolveModelUser` | |

## Seguridad

- Los tokens se guardan cifrados en la sesión del servidor y nunca llegan a props, JavaScript ni logs.
- El *issuer* es fijo y se compara literalmente con el `iss` de cada ID Token y con el documento de descubrimiento.
- El secreto del cliente vive solo en la configuración del backend.

## Desarrollo del paquete

```bash
vendor/bin/pest
```
