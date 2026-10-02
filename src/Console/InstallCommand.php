<?php

namespace LuisML\AccountsClient\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'accounts:install
        {--migrate : Run the migrations right away}
        {--remove-auth : Delete the local password/2FA/registration files that conflict with Accounts and empty the Fortify features}
        {--force : Do not ask for confirmation before deleting}';

    protected $description = 'Add the ACCOUNTS_* variables, report local auth that conflicts with Accounts and print the remaining steps';

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
        'resources/js/components/manage-two-factor.tsx' => 'import:@/components/manage-two-factor',
        'resources/js/components/two-factor-recovery-codes.tsx' => 'import:@/components/two-factor-recovery-codes',
        'resources/js/components/two-factor-setup-modal.tsx' => 'import:@/components/two-factor-setup-modal',
        'resources/js/components/manage-passkeys.tsx' => 'import:@/components/manage-passkeys',
        'resources/js/components/passkey-verify.tsx' => 'import:@/components/passkey-verify',
        'resources/js/hooks/use-two-factor-auth.ts' => 'import:@/hooks/use-two-factor-auth',
        'app/Http/Controllers/Settings/SecurityController.php' => 'class:SecurityController',
        'app/Http/Requests/Settings/PasswordUpdateRequest.php' => 'class:PasswordUpdateRequest',
        'app/Http/Requests/Settings/TwoFactorAuthenticationRequest.php' => 'class:TwoFactorAuthenticationRequest',
        'tests/Feature/Auth/RegistrationTest.php' => 'test',
        'tests/Feature/Auth/PasswordResetTest.php' => 'test',
        'tests/Feature/Auth/EmailVerificationTest.php' => 'test',
        'tests/Feature/Auth/VerificationNotificationTest.php' => 'test',
        'tests/Feature/Auth/PasswordConfirmationTest.php' => 'test',
        'tests/Feature/Auth/TwoFactorChallengeTest.php' => 'test',
        'tests/Feature/Settings/SecurityTest.php' => 'test',
    ];

    private const VARIABLES = [
        'ACCOUNTS_ISSUER' => '',
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

        $callback = rtrim((string) config('app.url'), '/').'/'.trim(config('accounts.routes.prefix', 'auth/accounts'), '/').'/callback';

        $this->newLine();
        $this->info('Remaining steps:');
        $this->line("  1. Register this app in Accounts (/admin/applications) with the exact callback: {$callback}");
        $this->line('  2. Put the client id and secret it shows in .env (ACCOUNTS_ISSUER, ACCOUNTS_CLIENT_ID, ACCOUNTS_CLIENT_SECRET).');
        $this->line('  3. '.($this->option('migrate') ? 'Migrations done.' : 'Run `php artisan migrate` (adds accounts_issuer / accounts_sub to users).'));
        $this->line("  4. Protect routes with the `accounts.access` and `accounts.activity` middleware and link a button to route('accounts.login').");

        return self::SUCCESS;
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
