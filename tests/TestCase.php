<?php

namespace LuisML\AccountsClient\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LuisML\AccountsClient\AccountsServiceProvider;
use LuisML\AccountsClient\Tests\Fixtures\FakeAccounts;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected FakeAccounts $accounts;

    protected function getPackageProviders($app): array
    {
        return [AccountsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('accounts.issuer', 'https://accounts.example.test');
        $app['config']->set('accounts.client_id', 'client-id-1');
        $app['config']->set('accounts.client_secret', 'client-secret-1');
        $app['config']->set('accounts.redirect', 'https://app.example.test/auth/accounts/callback');
        $app['config']->set('accounts.home', '/');
        $app['config']->set('accounts.user_model', Fixtures\User::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        $this->artisan('migrate', ['--path' => __DIR__.'/../database/migrations', '--realpath' => true]);

        $this->accounts = new FakeAccounts;
        $this->accounts->install();

        $this->app['router']->middleware(['web', 'accounts.access', 'accounts.activity'])->group(function ($router) {
            $router->get('/protected', fn () => 'ok')->name('protected');
            $router->post('/protected', fn () => 'changed')->name('protected.change');
            $router->get('/polling', fn () => 'ok')->name('polling');
        });
    }

    /**
     * Start a login and return what Accounts would receive.
     *
     * @return array{state: string, nonce: string, location: string, query: array<string, string>}
     */
    protected function beginLogin(): array
    {
        $response = $this->get('/auth/accounts/redirect')->assertRedirect();
        $location = $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->accounts->lastNonce = ['nonce' => $query['nonce']];

        return ['state' => $query['state'], 'nonce' => $query['nonce'], 'location' => $location, 'query' => $query];
    }

    /**
     * Run the whole login and return the callback response.
     */
    protected function login(): \Illuminate\Testing\TestResponse
    {
        $login = $this->beginLogin();

        return $this->get('/auth/accounts/callback?'.http_build_query(['code' => 'code-1', 'state' => $login['state']]));
    }
}
