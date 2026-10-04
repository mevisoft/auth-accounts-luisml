<?php

namespace LuisML\AccountsClient;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LuisML\AccountsClient\Console\InstallCommand;
use LuisML\AccountsClient\Http\Middleware\EnsureAccountsAccess;
use LuisML\AccountsClient\Http\Middleware\ReportAccountsActivity;
use LuisML\AccountsClient\Http\Middleware\RequireAccountsAssurance;

class AccountsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/accounts.php', 'accounts');
    }

    public function boot(Router $router): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'accounts');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $router->aliasMiddleware('accounts.access', EnsureAccountsAccess::class);
        $router->aliasMiddleware('accounts.activity', ReportAccountsActivity::class);
        $router->aliasMiddleware('accounts.step-up', RequireAccountsAssurance::class);

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }

        $this->publishes([__DIR__.'/../config/accounts.php' => config_path('accounts.php')], 'accounts-config');
    }
}
