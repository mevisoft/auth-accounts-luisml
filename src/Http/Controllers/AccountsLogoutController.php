<?php

namespace LuisML\AccountsClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use LuisML\AccountsClient\AccountsSession;
use LuisML\AccountsClient\Actions\AccountsHttp;
use LuisML\AccountsClient\Actions\Discovery;
use LuisML\AccountsClient\Http\Controllers\Concerns\LeavesTheApplication;
use LuisML\AccountsClient\Jobs\RevokeAccountsAccess;
use Throwable;

class AccountsLogoutController extends Controller
{
    use LeavesTheApplication;

    /**
     * Local sign-out: destroy this app's session always, and ask Accounts to revoke this browser's
     * access. If that cannot be confirmed now, a durable job retries it and the person is told.
     * It never affects other apps, other browsers or the consent.
     */
    public function __invoke(Request $request, AccountsHttp $http, Discovery $discovery)
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

        $endSession = $this->centralLogoutUrl($state->idToken(), $discovery);

        $state->forget();
        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($endSession !== null) {
            return $this->leaveTo($request, $endSession);
        }

        return redirect()->to(config('accounts.home'))->with('status', $confirmed
            ? 'Cerraste sesión.'
            : 'Cerraste sesión aquí. No pudimos confirmar el cierre en LuisML todavía; lo reintentaremos.');
    }

    /**
     * With `global_logout`, where to send the browser to end the central session too. Null when it is
     * off, when this session has no ID Token to prove who is asking, or when Accounts has no endpoint.
     */
    private function centralLogoutUrl(?string $idToken, Discovery $discovery): ?string
    {
        if (! config('accounts.global_logout') || $idToken === null) {
            return null;
        }

        try {
            $endpoint = $discovery->metadata()['end_session_endpoint'] ?? null;
        } catch (Throwable) {
            return null;
        }

        if (! is_string($endpoint)) {
            return null;
        }

        $redirect = config('accounts.post_logout_redirect');

        return $endpoint.'?'.http_build_query(array_filter([
            'id_token_hint' => $idToken,
            'post_logout_redirect_uri' => is_string($redirect) && $redirect !== '' ? $redirect : null,
            'state' => Str::random(20),
        ]));
    }
}
