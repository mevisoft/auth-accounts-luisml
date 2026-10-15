# Usuarios y perfil central

## Where to Find It

Search with `search-docs`:
- `"accounts user_resolver"` for custom user creation
- `"accounts profile sync"` for how central profile changes arrive

Source: `src/Actions/ResolveModelUser.php`, `src/Actions/SyncProfile.php`, `config/accounts.php` (`users_table`, `user_model`, `user_resolver`).

## What to Watch For

### La identidad se vincula por `(issuer, sub)`, nunca por correo

Un usuario local con el mismo correo no se toma ni se enlaza. `ResolveModelUser` busca por `(accounts_issuer, accounts_sub)`, copia `name`, `email`, `accounts_roles` y `email_verified_at`, y a un usuario nuevo le pone una contraseña aleatoria inutilizable. La migración añade `accounts_issuer`, `accounts_sub` (únicas juntas) y `accounts_roles` (JSON) a `users`.

### Lógica extra al crear usuario: resolver propio

```php
'user_resolver' => App\Actions\Accounts\ResolveAccountsUser::class,
```

Clase invocable `(Identity): ?Authenticatable` (`null` rechaza el acceso). Llama a `ResolveModelUser` y añade lo tuyo si `$user->wasRecentlyCreated`. Entrar con Accounts no concede ningún rol de negocio.

### Nombre, correo y contraseña se editan solo en Accounts

No ofrezcas formulario propio: enlaza a `accounts_account_url()`. Los cambios llegan solos: la introspección de `accounts.access` (≤60 s) devuelve `accounts_profile_version`; si cambia, `SyncProfile` lee UserInfo y actualiza el usuario con tu `user_resolver`. Si UserInfo falla, la petición sigue y se reintenta en la siguiente validación.
