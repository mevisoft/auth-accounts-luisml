<?php

use Illuminate\Support\Facades\Route;
use LuisML\AccountsClient\Http\Controllers\AccountsCallbackController;
use LuisML\AccountsClient\Http\Controllers\AccountsLoginController;
use LuisML\AccountsClient\Http\Controllers\AccountsLogoutController;

Route::middleware('web')->prefix(config('accounts.routes.prefix', 'auth/accounts'))->group(function () {
    Route::get('redirect', AccountsLoginController::class)->name('accounts.login');
    Route::get('callback', AccountsCallbackController::class)->name('accounts.callback');
    Route::post('logout', AccountsLogoutController::class)->name('accounts.logout');
});
