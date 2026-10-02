<?php

namespace LuisML\AccountsClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use LuisML\AccountsClient\AccountsSession;
use LuisML\AccountsClient\Actions\AccountsHttp;
use LuisML\AccountsClient\Jobs\RevokeAccountsAccess;
use Throwable;

class AccountsLogoutController extends Controller
{
    /**
     * Local sign-out: destroy this app's session always, and ask Accounts to revoke this browser's
     * access. If that cannot be confirmed now, a durable job retries it and the person is told.
     * It never affects other apps, other browsers or the consent.
     */
    public function __invoke(Request $request, AccountsHttp $http)
    {
        $state = AccountsSession::for($request->session());
        $token = $state->refreshToken() ?? $state->accessToken();
        $confirmed = $token === null;

        if ($token !== null) {
            try {
                $confirmed = $http->post('/oauth/revoke', ['token' => $token])->successful();
            } catch (Throwable) {
                $confirmed = false;
            }

            if (! $confirmed) {
                RevokeAccountsAccess::dispatch(Crypt::encryptString($token));
            }
        }

        $state->forget();
        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(config('accounts.home'))->with('status', $confirmed
            ? 'Cerraste sesión.'
            : 'Cerraste sesión aquí. No pudimos confirmar el cierre en LuisML todavía; lo reintentaremos.');
    }
}
