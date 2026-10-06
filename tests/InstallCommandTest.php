<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/accounts-install-'.bin2hex(random_bytes(4));
    File::makeDirectory($this->dir);
    $this->app->setBasePath($this->dir);
});

afterEach(fn () => File::deleteDirectory($this->dir));

test('it adds the variables once and keeps the ones already set', function () {
    File::put($this->dir.'/.env.example', "APP_NAME=App\nACCOUNTS_ISSUER=https://accounts.example.test");

    $this->artisan('accounts:install')->assertSuccessful();
    $this->artisan('accounts:install')->assertSuccessful();

    $contents = File::get($this->dir.'/.env.example');

    expect(substr_count($contents, 'ACCOUNTS_ISSUER='))->toBe(1)
        ->and($contents)->toContain('ACCOUNTS_ISSUER=https://accounts.example.test')
        ->and(substr_count($contents, 'ACCOUNTS_CLIENT_ID='))->toBe(1)
        ->and($contents)->toContain('ACCOUNTS_REDIRECT_URI="${APP_URL}/auth/accounts/callback"');
});

test('it works without any env file', function () {
    $this->artisan('accounts:install')->assertSuccessful();

    expect(File::exists($this->dir.'/.env'))->toBeFalse();
});

function writeProjectFile(string $dir, string $path, string $contents = '// x'): void
{
    File::ensureDirectoryExists(dirname($dir.'/'.$path));
    File::put($dir.'/'.$path, $contents);
}

test('it reports the conflicting local auth without deleting anything', function () {
    writeProjectFile($this->dir, 'resources/js/pages/auth/register.tsx');
    writeProjectFile($this->dir, 'config/fortify.php', "<?php\nreturn [\n    'features' => [\n        Features::registration(),\n    ],\n];\n");

    $this->artisan('accounts:install')
        ->expectsOutputToContain('resources/js/pages/auth/register.tsx')
        ->expectsOutputToContain('config/fortify.php')
        ->assertSuccessful();

    expect(File::exists($this->dir.'/resources/js/pages/auth/register.tsx'))->toBeTrue();
});

test('--remove-auth deletes the conflicts and empties the Fortify features', function () {
    writeProjectFile($this->dir, 'resources/js/pages/auth/register.tsx');
    writeProjectFile($this->dir, 'tests/Feature/Auth/RegistrationTest.php');
    writeProjectFile($this->dir, 'config/fortify.php', "<?php\nreturn [\n    'guard' => 'web',\n    'features' => [\n        Features::registration(),\n        Features::resetPasswords(),\n    ],\n];\n");

    $this->artisan('accounts:install', ['--remove-auth' => true, '--force' => true])->assertSuccessful();

    expect(File::exists($this->dir.'/resources/js/pages/auth/register.tsx'))->toBeFalse()
        ->and(File::exists($this->dir.'/tests/Feature/Auth/RegistrationTest.php'))->toBeFalse()
        ->and(File::get($this->dir.'/config/fortify.php'))->toContain("'features' => [],")->toContain("'guard' => 'web'");
});

test('--remove-auth keeps a file that other code still uses', function () {
    writeProjectFile($this->dir, 'app/Http/Controllers/Settings/SecurityController.php');
    writeProjectFile($this->dir, 'routes/settings.php', "Route::get('settings/security', [SecurityController::class, 'edit']);");

    $this->artisan('accounts:install', ['--remove-auth' => true, '--force' => true])
        ->expectsOutputToContain('routes/settings.php')
        ->assertSuccessful();

    expect(File::exists($this->dir.'/app/Http/Controllers/Settings/SecurityController.php'))->toBeTrue();
});

test('--remove-auth preserves dependencies of retained conflicts transitively', function () {
    writeProjectFile($this->dir, 'routes/settings.php', "Route::get('settings/security', [SecurityController::class, 'edit']);");
    writeProjectFile($this->dir, 'app/Http/Controllers/Settings/SecurityController.php', "return Inertia::render('settings/security');");
    writeProjectFile($this->dir, 'resources/js/pages/settings/security.tsx', "import ManageTwoFactor from '@/components/manage-two-factor';");
    writeProjectFile($this->dir, 'resources/js/components/manage-two-factor.tsx', "import useTwoFactor from '@/hooks/use-two-factor-auth';");
    writeProjectFile($this->dir, 'resources/js/hooks/use-two-factor-auth.ts');
    writeProjectFile($this->dir, 'resources/js/pages/auth/register.tsx');

    $this->artisan('accounts:install', ['--remove-auth' => true, '--force' => true])->assertSuccessful();

    foreach (['app/Http/Controllers/Settings/SecurityController.php', 'resources/js/pages/settings/security.tsx',
        'resources/js/components/manage-two-factor.tsx', 'resources/js/hooks/use-two-factor-auth.ts'] as $file) {
        expect(File::exists($this->dir.'/'.$file))->toBeTrue();
    }

    expect(File::exists($this->dir.'/resources/js/pages/auth/register.tsx'))->toBeFalse();
});

test('it reports route groups without accounts.access and --protect-routes fixes them once', function () {
    writeProjectFile($this->dir, 'routes/web.php', "Route::middleware(['auth', 'verified'])->group(fn () => 1);\nRoute::middleware(['auth', 'accounts.access'])->group(fn () => 2);\nRoute::get('/')->middleware(['throttle:6,1']);");

    $this->artisan('accounts:install')->expectsOutputToContain('routes/web.php: 1 group(s)')->assertSuccessful();

    $this->artisan('accounts:install', ['--protect-routes' => true])->assertSuccessful();
    $this->artisan('accounts:install', ['--protect-routes' => true])->assertSuccessful();

    $routes = File::get($this->dir.'/routes/web.php');

    expect($routes)->toContain("middleware(['auth', 'accounts.access', 'accounts.activity', 'verified'])")
        ->and(substr_count($routes, 'accounts.access'))->toBe(2)
        ->and($routes)->toContain("middleware(['auth', 'accounts.access'])")
        ->and($routes)->toContain("middleware(['throttle:6,1'])");
});

test('--test-helper adds an actingAs override with an Accounts session, once', function () {
    writeProjectFile($this->dir, 'tests/TestCase.php', "<?php\n\nnamespace Tests;\n\nabstract class TestCase extends \\Illuminate\\Foundation\\Testing\\TestCase\n{\n    //\n}\n");

    $this->artisan('accounts:install', ['--test-helper' => true])->assertSuccessful();
    $this->artisan('accounts:install', ['--test-helper' => true])->assertSuccessful();

    $contents = File::get($this->dir.'/tests/TestCase.php');

    expect(substr_count($contents, 'function actingAs'))->toBe(1)
        ->and($contents)->toContain("'accounts.session'");
    expect(shell_exec('php -l '.escapeshellarg($this->dir.'/tests/TestCase.php').' 2>&1'))->toContain('No syntax errors');
});

test('--check reports an issuer that differs from the discovery document', function () {
    \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory);
    \Illuminate\Support\Facades\Http::fake(['*/.well-known/openid-configuration' => \Illuminate\Support\Facades\Http::response(['issuer' => 'https://other.example.test'])]);

    \Illuminate\Support\Facades\Artisan::call('accounts:install', ['--check' => true]);

    expect(\Illuminate\Support\Facades\Artisan::output())->toContain('Issuer mismatch');
});

test('--check passes when the discovery issuer matches', function () {
    \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory);
    \Illuminate\Support\Facades\Http::fake(['*/.well-known/openid-configuration' => \Illuminate\Support\Facades\Http::response(['issuer' => 'https://accounts.example.test'])]);

    $this->artisan('accounts:install', ['--check' => true])
        ->expectsOutputToContain('its issuer matches')
        ->assertSuccessful();
});

test('--check warns when Accounts does not advertise logout or assurance levels', function () {
    \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory);
    \Illuminate\Support\Facades\Http::fake(['*/.well-known/openid-configuration' => \Illuminate\Support\Facades\Http::response(['issuer' => 'https://accounts.example.test'])]);

    \Illuminate\Support\Facades\Artisan::call('accounts:install', ['--check' => true]);

    expect(\Illuminate\Support\Facades\Artisan::output())->toContain('end_session_endpoint')->toContain('acr_values_supported');
});

test('it restores the Inertia type import that deleting files can orphan', function () {
    writeProjectFile($this->dir, 'resources/js/types/global.d.ts', "import type { Auth } from '@/types/auth';\n\ndeclare module '@inertiajs/core' {}\n");
    writeProjectFile($this->dir, 'node_modules/@inertiajs/core/package.json', '{}');

    $this->artisan('accounts:install')->assertSuccessful();
    $this->artisan('accounts:install')->assertSuccessful();

    $types = File::get($this->dir.'/resources/js/types/global.d.ts');

    expect(substr_count($types, "import '@inertiajs/core'"))->toBe(1)
        ->and($types)->toStartWith("import '@inertiajs/core';");
});

test('it reports frontend files that import removed routes', function () {
    writeProjectFile($this->dir, 'resources/js/pages/settings/profile.tsx', "import { send } from '@/routes/verification';");
    writeProjectFile($this->dir, 'resources/js/routes/verification/index.ts', "import '@/routes/verification';");

    $this->artisan('accounts:install')
        ->expectsOutputToContain('resources/js/pages/settings/profile.tsx (@/routes/verification)')
        ->assertSuccessful();
});
