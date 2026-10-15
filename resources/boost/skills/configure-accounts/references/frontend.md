# Frontend: Inertia + React + Wayfinder

## Where to Find It

La librería **no trae frontend**: solo backend y las vistas Blade `login`, `signed-out` y `unavailable`. Sidebar, dropdown y perfil los escribe cada app. La referencia es `setup-server.luisml`:
- `resources/js/components/nav-user.tsx`, `user-info.tsx`, `user-menu-content.tsx`
- `resources/js/pages/settings/profile.tsx`, `layouts/settings/layout.tsx`, `pages/welcome.tsx`

## What to Watch For

### Imports de Wayfinder

Rutas generadas en `resources/js/routes/accounts/index.ts`. `switch` es palabra reservada, así que se exporta como `switchMethod`.

```ts
import { login, logout, switchMethod } from '@/routes/accounts';
```

Tipa `accountsUrl: string` en `resources/js/types/global.d.ts` junto a `auth`, `name` y `sidebarOpen`.

### Dropdown del usuario

- `app-sidebar.tsx` renderiza `<NavUser />` en el `SidebarFooter`.
- `NavUser` lee `usePage().props.auth`; sin `auth.user` devuelve `null`.
- `UserInfo` muestra avatar (fallback a iniciales con `useInitials`), `name` y, con `showEmail`, `email`. La librería no escribe ningún campo de avatar.

### `UserMenuContent` es el cambio clave

1. Cabecera: `UserInfo` con `showEmail`.
2. **Settings** → `<Link href={edit()} prefetch onClick={cleanup}>` con `edit` de `@/routes/profile`.
3. **Gestionar cuenta** (solo si `accountsUrl`) → `<a href={accountsUrl} target="_blank" rel="noopener" data-test="manage-account-link">`. Ancla normal, no `Link`: sale de la app.
4. **Switch account** → `<Link href={switchMethod()} as="button" onClick={handleLogout} data-test="switch-account-button">`.
5. **Log out** → `<Link href={logout()} as="button" onClick={handleLogout} data-test="logout-button">`.

`handleLogout` ejecuta `cleanup()` (de `useMobileNavigation`) y `router.flushAll()` para vaciar la caché de Inertia. `logout()` y `switchMethod()` son rutas POST de Wayfinder; `Link as="button"` las envía por POST con CSRF.

### `settings/profile` es de solo lectura

- Ruta `GET settings/profile` (`profile.edit`) dentro de `['auth', 'accounts.access', 'accounts.activity']`; `settings` redirige a ella. `ProfileController::edit` solo hace `Inertia::render('settings/profile')`.
- Sin formulario: nombre, correo, contraseña y borrado se gestionan en Accounts. `PATCH /settings/profile` debe devolver 405.
- La página muestra `auth.user.name` y `email` en una `<dl>` y, con `accountsUrl`, un `<Button asChild><a href={accountsUrl}>` (`data-test="manage-account-button"`).
- El menú de Settings solo tiene **Profile** y **Appearance**; nada de Password / Security / Two-factor.

### Botones de login son anclas

En `welcome.tsx`, sin `auth.user` usa `<a href={login().url}>` (no `Link`: sale de la app). Con sesión, `Link` a `dashboard().url`.
