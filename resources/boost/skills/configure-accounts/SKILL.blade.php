---
name: configuring-accounts
description: "Use this skill whenever the user mentions Accounts LuisML, mevisoft/auth-accounts-luisml, LuisML\\AccountsClient or 'Continuar con LuisML' in a Laravel context. Covers the full lifecycle: installing the package (accounts:install, ACCOUNTS_* variables, migrations), protecting routes (accounts.access, accounts.activity, accounts.step-up), login/logout/switch account and central logout, roles (HasAccountsRoles, Gates), the central profile and user_resolver, the Inertia/React sidebar menu and read-only settings/profile, and testing with actingAs. Do not use for Fortify, Socialite, Passport or Sanctum without Accounts, generic OpenID Connect providers, or the Accounts server itself."
license: MIT
metadata:
  author: luisml
---
@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Accounts Configuration

Librería Laravel (PHP ^8.2, Laravel 11–13) que convierte la app en cliente OpenID Connect (PKCE) de Accounts LuisML. La app no gestiona contraseñas: Accounts decide quién entra y cuánto dura la sesión.

## Documentation

Use `search-docs` for detailed Accounts patterns, and read `README.md` and `src/` of the package before assuming anything not covered here.

For deeper guidance on specific topics, read the relevant reference file before implementing:

- `references/backend.md` covers middleware, roles and Gates, shared Inertia props, the registered routes and central logout
- `references/frontend.md` covers the sidebar user menu, Switch account, read-only `settings/profile` and Wayfinder imports (the package ships no frontend)
- `references/users-profile.md` covers `(issuer, sub)` linking, `user_resolver` and the central profile sync
- `references/testing.md` covers the `actingAs()` override with a validated Accounts session

## Basic Usage

### Installation

```bash
composer require mevisoft/auth-accounts-luisml:@dev
{{ $assist->artisanCommand('accounts:install --migrate') }}
```

Other `accounts:install` options: `--protect-routes`, `--test-helper`, `--remove-auth` (asks for confirmation; `--force` skips it) and `--check`. The migration adds `accounts_issuer`, `accounts_sub` and `accounts_roles` to `users`.

### Environment

Register the app in Accounts (`/admin/applications`) with the exact callback, then fill `.env`:

@boostsnippet("Environment", "dotenv")
ACCOUNTS_ISSUER=https://accounts.luisml.com
ACCOUNTS_CLIENT_ID=
ACCOUNTS_CLIENT_SECRET=
ACCOUNTS_REDIRECT_URI="${APP_URL}/auth/accounts/callback"
ACCOUNTS_HOME=/dashboard
@endboostsnippet

### Protected Routes

@boostsnippet("Route Group", "php")
Route::middleware(['auth', 'accounts.access', 'accounts.activity'])->group(function () {
    // protected routes
});
@endboostsnippet

### User Model and Roles

@boostsnippet("User Model", "php")
use LuisML\AccountsClient\Concerns\HasAccountsRoles;
use LuisML\AccountsClient\Roles;

class User extends Authenticatable
{
    use HasAccountsRoles;
}

Gate::define('manage-servers', fn (User $user): bool => $user->hasAccountsRole(Roles::ADMIN));
@endboostsnippet

### Login and Logout

Link to `route('accounts.login')` with a plain anchor (`login().url` with Wayfinder) and log out with `POST route('accounts.logout')`. Use `POST route('accounts.switch')` for Switch account.

## Verification

1. Run `{{ $assist->artisanCommand('accounts:install --check') }}` and confirm the discovery issuer matches `ACCOUNTS_ISSUER`
2. Confirm the callback registered in Accounts is exactly `ACCOUNTS_REDIRECT_URI` (HTTPS in production)
3. Visit a protected route as a guest and check it ends in Accounts, then log in and out
4. Run the test suite with the `actingAs()` override from `references/testing.md`

## Common Pitfalls

- Never link to `accounts.callback` or `accounts.backchannel-logout`: the first needs `code` and `state`, the second is server-to-server.
- Identities are linked by `(issuer, sub)`, never by email. A local user with the same email is not taken over.
- Name, email and password are edited only in Accounts. Link to `accounts_account_url()` instead of building a form.
- Login buttons are plain `<a href>`, not Inertia `Link`: they leave the app. Logout and Switch account use `Link as="button"` and `router.flushAll()`.
- Token refresh, back-channel logout and revocation retries need a persistent shared cache with locks and a persistent queue with a worker. `array` and `sync` are for tests only.
- Do not raise `validation_seconds` (60) without measuring it; `clock_skew_seconds` never extends that bound.
- `ACCOUNTS_POST_LOGOUT_REDIRECT_URI` and the signed-out page must be registered in Accounts.
- Strict mode requires an Accounts session for every `actingAs`; `accounts.access:lenient` is only for apps that keep their own login.
- The library provides no avatar: it never writes an avatar field.
- Always check `config/accounts.php` before making changes and use `search-docs` rather than relying on this skill alone.
