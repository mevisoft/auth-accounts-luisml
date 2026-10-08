<?php

use Illuminate\Support\Facades\Route;
use LuisML\AccountsClient\Http\Controllers\AccountsBackchannelLogoutController;
use LuisML\AccountsClient\Http\Controllers\AccountsCallbackController;
use LuisML\AccountsClient\Http\Controllers\AccountsEntryController;
use LuisML\AccountsClient\Http\Controllers\AccountsLoginController;
use LuisML\AccountsClient\Http\Controllers\AccountsLogoutController;

Route::middleware('web')->prefix(config('accounts.routes.prefix', 'auth/accounts'))->group(function () {
    Route::get('redirect', AccountsLoginController::class)->name('accounts.login');
    Route::get('callback', AccountsCallbackController::class)->name('accounts.callback');
    Route::post('logout', AccountsLogoutController::class)->name('accounts.logout');
});

// Server-to-server: no cookies, no CSRF; the signed logout token is the credential.
Route::prefix(config('accounts.routes.prefix', 'auth/accounts'))->group(function () {
    Route::post('backchannel-logout', AccountsBackchannelLogoutController::class)->name('accounts.backchannel-logout');
});

// The `login` address guests are sent to: straight on to Accounts, with a guard against loops.
if (config('accounts.routes.login', true)) {
    Route::middleware('web')->get(config('accounts.routes.login_path', 'login'), AccountsEntryController::class)->name(config('accounts.routes.login_name', 'login'));
}
