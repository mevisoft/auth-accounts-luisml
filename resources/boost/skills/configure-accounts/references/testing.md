# Tests en la app

## Where to Find It

`php artisan accounts:install --test-helper` añade el override a `tests/TestCase.php`. Ejemplo de referencia: `tests/TestCase.php` de `setup-server.luisml`.

## What to Watch For

### Con el modo estricto, todo `actingAs` necesita una sesión de Accounts validada

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

Sin esa sesión, `accounts.access` cierra la sesión y las rutas protegidas redirigen.

### Pruebas que vale la pena tener

- Invitado a `route('login')` redirige a `route('accounts.login')`.
- No hay login por contraseña: `POST /login` devuelve 405.
- `PATCH /settings/profile` devuelve 405 y el nombre no cambia.
- La página de perfil expone `accountsUrl` = `{issuer}/account`.
