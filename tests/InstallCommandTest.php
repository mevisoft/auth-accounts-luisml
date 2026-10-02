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
