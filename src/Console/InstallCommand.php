<?php

namespace LuisML\AccountsClient\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'accounts:install
        {--migrate : Run the migrations right away}
        {--remove-auth : Delete the local password/2FA/registration files that conflict with Accounts and empty the Fortify features}
        {--protect-routes : Add accounts.access and accounts.activity to every route group that uses the auth middleware}
        {--test-helper : Make actingAs() in tests/TestCase.php open a validated Accounts session}
        {--check : Verify the configuration and that Accounts answers with the expected issuer}
        {--force : Do not ask for confirmation before deleting}';

    protected $description = 'Set this app up for Accounts: variables, conflicting local auth, route protection, test helper and checks';

    /**
     * Local authentication that Accounts replaces, as laravel/starter-kit style apps ship it.
     * `reference` is how other code points at the file: a class name, an import path or an Inertia page.
     *
     * @var array<string, string>
     */
    private const CONFLICTS = [
        'resources/js/pages/auth/register.tsx' => 'page:auth/register',
        'resources/js/pages/auth/forgot-password.tsx' => 'page:auth/forgot-password',
        'resources/js/pages/auth/reset-password.tsx' => 'page:auth/reset-password',
        'resources/js/pages/auth/verify-email.tsx' => 'page:auth/verify-email',
        'resources/js/pages/auth/two-factor-challenge.tsx' => 'page:auth/two-factor-challenge',
        'resources/js/pages/auth/confirm-password.tsx' => 'page:auth/confirm-password',
        'resources/js/pages/settings/security.tsx' => 'page:settings/security',
        'resources/js/pages/settings/password.tsx' => 'page:settings/password',
        'resources/js/pages/settings/two-factor.tsx' => 'page:settings/two-factor',
        'resources/js/components/manage-two-factor.tsx' => 'import:@/components/manage-two-factor',
        'resources/js/components/two-factor-recovery-codes.tsx' => 'import:@/components/two-factor-recovery-codes',
        'resources/js/components/two-factor-setup-modal.tsx' => 'import:@/components/two-factor-setup-modal',
        'resources/js/components/manage-passkeys.tsx' => 'import:@/components/manage-passkeys',
        'resources/js/components/passkey-verify.tsx' => 'import:@/components/passkey-verify',
        'resources/js/hooks/use-two-factor-auth.ts' => 'import:@/hooks/use-two-factor-auth',
        'app/Http/Controllers/Settings/SecurityController.php' => 'class:SecurityController',
        'app/Http/Controllers/Settings/PasswordController.php' => 'class:PasswordController',
        'app/Http/Controllers/Settings/TwoFactorAuthenticationController.php' => 'class:TwoFactorAuthenticationController',
        'app/Actions/Fortify/CreateNewUser.php' => 'class:CreateNewUser',
        'app/Actions/Fortify/ResetUserPassword.php' => 'class:ResetUserPassword',
        'app/Http/Requests/Settings/PasswordUpdateRequest.php' => 'class:PasswordUpdateRequest',
        'app/Http/Requests/Settings/TwoFactorAuthenticationRequest.php' => 'class:TwoFactorAuthenticationRequest',
        'tests/Feature/Auth/RegistrationTest.php' => 'test',
        'tests/Feature/Auth/PasswordResetTest.php' => 'test',
        'tests/Feature/Auth/EmailVerificationTest.php' => 'test',
        'tests/Feature/Auth/VerificationNotificationTest.php' => 'test',
        'tests/Feature/Auth/PasswordConfirmationTest.php' => 'test',
        'tests/Feature/Auth/TwoFactorChallengeTest.php' => 'test',
        'tests/Feature/Settings/SecurityTest.php' => 'test',
        'tests/Feature/Settings/PasswordUpdateTest.php' => 'test',
        'tests/Feature/Settings/TwoFactorAuthenticationTest.php' => 'test',
    ];

    private const VARIABLES = [
        'ACCOUNTS_ISSUER' => 'https://accounts.luisml.com',
        'ACCOUNTS_CLIENT_ID' => '',
        'ACCOUNTS_CLIENT_SECRET' => '',
        'ACCOUNTS_REDIRECT_URI' => '"${APP_URL}/auth/accounts/callback"',
        'ACCOUNTS_HOME' => '/',
    ];

    public function handle(): int
    {
        foreach (['.env.example', '.env'] as $file) {
            $this->addMissingVariables(base_path($file));
        }

        if ($this->option('migrate')) {
            $this->call('migrate');
        }

        $this->handleConflicts();
        $this->keepInertiaTypes();
        $this->reportStaleImports();
        $this->handleRoutes();

        if ($this->option('test-helper')) {
            $this->installTestHelper();
        }

        $this->warnAboutEmptyVariables();

        if ($this->option('check')) {
            $this->checkAccounts();
        }

        $callback = rtrim((string) config('app.url'), '/').'/'.trim(config('accounts.routes.prefix', 'auth/accounts'), '/').'/callback';

        $this->newLine();
        $this->info('Remaining steps:');
        $this->line("  1. Register this app in Accounts (/admin/applications) with the exact callback: {$callback}");
        $this->line('  2. Put the client id and secret it shows in .env (ACCOUNTS_ISSUER, ACCOUNTS_CLIENT_ID, ACCOUNTS_CLIENT_SECRET).');
        $this->line('  3. '.($this->option('migrate') ? 'Migrations done.' : 'Run `php artisan migrate` (adds accounts_issuer / accounts_sub to users).'));
        $this->line("  4. Link a button to route('accounts.login') and log out with POST route('accounts.logout').");
        $this->line('  5. Give this app its own registration in Accounts (own client id and secret): sharing one between apps shares consent and the secret.');
        $this->line('  6. Run `php artisan accounts:install --check` once the variables are filled to verify the connection.');

        return self::SUCCESS;
    }

    /**
     * Route files with an `auth` middleware array, and whether each array already carries accounts.access.
     *
     * @return array<string, array<int, string>> file => the arrays that lack it
     */
    private function unprotectedRouteArrays(): array
    {
        $result = [];

        foreach (File::glob(base_path('routes/*.php')) as $path) {
            preg_match_all('/middleware\(\[([^\]]*)\]\)/', File::get($path), $matches);

            $missing = collect($matches[1])
                ->filter(fn ($list) => preg_match('/[\'"]auth[\'"]/', $list) && ! str_contains($list, 'accounts.access'))
                ->values()
                ->all();

            if ($missing !== []) {
                $result[basename($path)] = $missing;
            }
        }

        return $result;
    }

    private function handleRoutes(): void
    {
        $unprotected = $this->unprotectedRouteArrays();

        if ($unprotected === []) {
            return;
        }

        if (! $this->option('protect-routes')) {
            $this->newLine();
            $this->warn('Route groups with `auth` but without `accounts.access` (the central session control does not apply to them):');

            foreach ($unprotected as $file => $arrays) {
                $this->line("  - routes/{$file}: ".count($arrays).' group(s)');
            }

            $this->line('  Run `php artisan accounts:install --protect-routes` to add accounts.access and accounts.activity.');

            return;
        }

        foreach (array_keys($unprotected) as $file) {
            $path = base_path("routes/{$file}");

            File::put($path, preg_replace_callback('/middleware\(\[([^\]]*)\]\)/', function ($match) {
                if (! preg_match('/[\'"]auth[\'"]/', $match[1]) || str_contains($match[1], 'accounts.access')) {
                    return $match[0];
                }

                return 'middleware(['.preg_replace('/([\'"]auth[\'"])/', "$1, 'accounts.access', 'accounts.activity'", $match[1], 1).'])';
            }, File::get($path)));

            $this->components->info("Protected the auth groups in routes/{$file}");
        }
    }

    private function installTestHelper(): void
    {
        $path = base_path('tests/TestCase.php');

        if (! File::exists($path)) {
            $this->components->warn('tests/TestCase.php not found: add the actingAs() override from the README by hand.');

            return;
        }

        $contents = File::get($path);

        if (str_contains($contents, 'accounts.session')) {
            return;
        }

        $method = <<<'PHP'

    /**
     * Every protected route requires an Accounts session, so acting as a user also opens one that was just validated.
     */
    public function actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null): static
    {
        return parent::actingAs($user, $guard)->withSession(['accounts.session' => [
            'subject' => 'test-subject',
            'access_token' => \Illuminate\Support\Facades\Crypt::encryptString('access-token'),
            'refresh_token' => \Illuminate\Support\Facades\Crypt::encryptString('refresh-token'),
            'access_expires_at' => now()->addHour()->getTimestamp(),
            'validated_until' => now()->addMinute()->getTimestamp(),
            'session_expires_at' => null,
            'last_report_at' => now()->getTimestamp(),
        ]]);
    }
PHP;

        $position = strrpos($contents, '}');

        if ($position === false) {
            return;
        }

        File::put($path, rtrim(substr($contents, 0, $position))."\n".$method."\n}\n");
        $this->components->info('Added actingAs() with an Accounts session to tests/TestCase.php');
    }

    private function warnAboutEmptyVariables(): void
    {
        $empty = collect(['ACCOUNTS_ISSUER' => 'issuer', 'ACCOUNTS_CLIENT_ID' => 'client_id', 'ACCOUNTS_CLIENT_SECRET' => 'client_secret'])
            ->filter(fn ($key) => blank(config("accounts.{$key}")))
            ->keys();

        if ($empty->isNotEmpty()) {
            $this->newLine();
            $this->warn('Still empty in the environment: '.$empty->implode(', '));
        }
    }

    /**
     * Fail early on the mistakes that otherwise show up as an opaque error in the middle of a login.
     */
    private function checkAccounts(): void
    {
        $this->newLine();
        $this->info('Checking the connection with Accounts:');

        $issuer = (string) config('accounts.issuer');

        if ($issuer === '') {
            $this->error('ACCOUNTS_ISSUER is empty.');

            return;
        }

        if (! str_starts_with($issuer, 'https://') && ! app()->isLocal()) {
            $this->components->warn('The issuer is not HTTPS; production needs HTTPS for issuer and callback.');
        }

        try {
            $document = \Illuminate\Support\Facades\Http::timeout(5)->get(rtrim($issuer, '/').'/.well-known/openid-configuration');
        } catch (\Throwable $exception) {
            $this->error("Accounts is not reachable at {$issuer}: ".$exception->getMessage());

            return;
        }

        if (! $document->successful()) {
            $this->error("Discovery answered HTTP {$document->status()}; is ACCOUNTS_ISSUER the Accounts root URL?");

            return;
        }

        if ($document->json('issuer') === $issuer) {
            $this->components->info('Discovery document found and its issuer matches.');
        } else {
            $this->error('Issuer mismatch: discovery says '.$document->json('issuer').", ACCOUNTS_ISSUER is {$issuer}. Every ID Token would be rejected.");
        }

        foreach (['end_session_endpoint' => 'RP-initiated logout (ACCOUNTS_GLOBAL_LOGOUT) will not work', 'acr_values_supported' => 'step-up (accounts.step-up) cannot raise the authentication level'] as $key => $consequence) {
            if ($document->json($key) === null) {
                $this->components->warn("Discovery does not advertise {$key}: {$consequence}. Update Accounts.");
            }
        }

        if (! str_starts_with((string) config('accounts.redirect'), 'http')) {
            $this->error('ACCOUNTS_REDIRECT_URI is not an absolute URL.');
        }

        $table = config('accounts.users_table', 'users');

        if (! \Illuminate\Support\Facades\Schema::hasColumns($table, ['accounts_issuer', 'accounts_sub'])) {
            $this->error("The {$table} table lacks accounts_issuer / accounts_sub: run `php artisan migrate`.");
        }
    }

    /**
     * Deleting the 2FA/passkey files can remove the only file that imported `@inertiajs/core`, and the
     * shared-props augmentation in global.d.ts then stops applying (every `auth` becomes `unknown`).
     */
    private function keepInertiaTypes(): void
    {
        $path = base_path('resources/js/types/global.d.ts');

        if (! File::exists($path) || ! File::isDirectory(base_path('node_modules/@inertiajs/core'))) {
            return;
        }

        $contents = File::get($path);

        if (str_contains($contents, "declare module '@inertiajs/core'") && ! str_contains($contents, "import '@inertiajs/core'")) {
            File::put($path, "import '@inertiajs/core';\n".$contents);
            $this->components->info("Added import '@inertiajs/core' to resources/js/types/global.d.ts so the shared props keep their types.");
        }
    }

    /**
     * Frontend code that still imports the routes of features Accounts replaced (it breaks the build).
     */
    private function reportStaleImports(): void
    {
        if (! File::isDirectory(base_path('resources/js'))) {
            return;
        }

        $stale = [];

        foreach (File::allFiles(base_path('resources/js')) as $file) {
            $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');

            if (! preg_match('/\.tsx?$/', $relative) || preg_match('#^resources/js/(actions|routes|wayfinder)/#', $relative)) {
                continue;
            }

            if (preg_match('#@/routes/(verification|two-factor|password|register|user-password|security)#', File::get($file->getPathname()), $match)) {
                $stale[$relative] = $match[1];
            }
        }

        if ($stale === []) {
            return;
        }

        $this->newLine();
        $this->warn('Frontend files still importing routes that no longer exist:');

        foreach ($stale as $file => $route) {
            $this->line("  - {$file} (@/routes/{$route})");
        }
    }

    private function handleConflicts(): void
    {
        $found = collect(self::CONFLICTS)->filter(fn ($reference, $file) => File::exists(base_path($file)));

        if ($found->isEmpty() && ! $this->fortifyHasFeatures()) {
            return;
        }

        $this->newLine();
        $this->warn('Local authentication that conflicts with Accounts:');

        if ($this->fortifyHasFeatures()) {
            $this->line("  - config/fortify.php enables features (registration, password reset, 2FA...): set 'features' => [].");
        }

        $blocked = $found->mapWithKeys(fn ($reference, $file) => [$file => $this->referencedBy($file, $reference, $found->keys()->all())])->filter();

        // A retained controller/page can depend on another conflict. Keep that dependency,
        // and its dependencies, instead of excluding every conflict from reference checks.
        do {
            $previousCount = $blocked->count();
            $removableFiles = $found->keys()->diff($blocked->keys())->all();

            foreach ($found->except($blocked->keys()->all()) as $file => $reference) {
                if (($usedBy = $this->referencedBy($file, $reference, $removableFiles)) !== null) {
                    $blocked->put($file, $usedBy);
                }
            }
        } while ($blocked->count() !== $previousCount);

        foreach ($found as $file => $reference) {
            $this->line("  - {$file}".($blocked->has($file) ? "  (still used by {$blocked[$file]}: edit that first)" : ''));
        }

        if (! $this->option('remove-auth')) {
            $this->line('  Run `php artisan accounts:install --remove-auth` to delete the files and empty the Fortify features.');
            $this->printManualSteps();

            return;
        }

        $removable = $found->keys()->reject(fn ($file) => $blocked->has($file));

        if ($removable->isNotEmpty() && ($this->option('force') || $this->confirm("Delete {$removable->count()} file(s) listed above?"))) {
            $removable->each(fn ($file) => File::delete(base_path($file)));
            $this->components->info("Deleted {$removable->count()} file(s).");
        }

        if ($this->fortifyHasFeatures() && ($this->option('force') || $this->confirm("Set 'features' => [] in config/fortify.php?"))) {
            $path = base_path('config/fortify.php');
            File::put($path, preg_replace("/'features' => \[.*?\n    \],/s", "'features' => [],", File::get($path)));
            $this->components->info("Emptied the Fortify features.");
        }

        $this->printManualSteps();
    }

    private function printManualSteps(): void
    {
        $this->newLine();
        $this->line('Do by hand (they depend on your app):');
        $this->line('  - FortifyServiceProvider: Fortify::authenticateUsing(fn () => null) and a login view with only the Accounts button.');
        $this->line("  - routes: remove the settings/security and settings/password routes and any register/verification links (welcome page, profile).");
        $this->line("  - user menu: point log out to route('accounts.logout').");
        $this->line('  - then run `php artisan wayfinder:generate`, `npm run types:check` and your tests.');
    }

    private function fortifyHasFeatures(): bool
    {
        $path = base_path('config/fortify.php');

        return File::exists($path) && preg_match("/'features' => \[\s*[A-Za-z]/", File::get($path)) === 1;
    }

    /**
     * The first file outside the conflict set that still points at this one, if any.
     *
     * @param  array<int, string>  $conflicts
     */
    private function referencedBy(string $file, string $reference, array $conflicts): ?string
    {
        [$kind, $needle] = array_pad(explode(':', $reference, 2), 2, null);

        $folders = match ($kind) {
            'class' => ['app', 'routes', 'bootstrap', 'config'],
            'import' => ['resources/js'],
            'page' => ['app', 'routes'],
            default => [],
        };

        foreach ($folders as $folder) {
            if (! File::isDirectory(base_path($folder))) {
                continue;
            }

            foreach (File::allFiles(base_path($folder)) as $candidate) {
                $relative = ltrim(str_replace(base_path(), '', $candidate->getPathname()), '/');

                if (in_array($relative, $conflicts, true) || str_starts_with($relative, 'resources/js/actions') || str_starts_with($relative, 'resources/js/routes') || str_starts_with($relative, 'resources/js/wayfinder') || ($kind === 'page' && str_ends_with($relative, 'FortifyServiceProvider.php'))) {
                    continue;
                }

                if (str_contains(File::get($candidate->getPathname()), $needle)) {
                    return $relative;
                }
            }
        }

        return null;
    }

    private function addMissingVariables(string $path): void
    {
        if (! File::exists($path)) {
            return;
        }

        $contents = File::get($path);
        $missing = collect(self::VARIABLES)->reject(fn ($default, $key) => preg_match('/^'.$key.'=/m', $contents));

        if ($missing->isEmpty()) {
            return;
        }

        File::append($path, (str_ends_with($contents, "\n") ? '' : "\n")."\n".$missing->map(fn ($default, $key) => "{$key}={$default}")->implode("\n")."\n");

        $this->components->info('Added '.$missing->keys()->implode(', ').' to '.basename($path));
    }
}
