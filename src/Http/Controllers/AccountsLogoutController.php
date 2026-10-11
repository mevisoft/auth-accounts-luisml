<?php

namespace LuisML\AccountsClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use LuisML\AccountsClient\Actions\Discovery;
use LuisML\AccountsClient\Actions\EndLocalAccess;
use LuisML\AccountsClient\Http\Controllers\Concerns\LeavesTheApplication;
use LuisML\AccountsClient\Support\SecurityLog;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AccountsLogoutController extends Controller
{
    use LeavesTheApplication;

    /**
     * Name of the cookie that remembers a central logout in progress, across the local session it destroys.
     */
    public const LOGOUT_COOKIE = 'accounts_logout';

    public const LOGOUT_COOKIE_MINUTES = 30;

    /**
     * Sign out: this application's session ends always, and then, with `global_logout`, the person is sent to
     * end the central session. When that cannot be done it is said so; the person is never told the central
     * session ended unless Accounts sent them back to confirm it.
     */
    public function __invoke(Request $request, EndLocalAccess $end, Discovery $discovery): Response
    {
        $ended = $end->handle($request);

        $note = match (true) {
            $ended['confirmed'] => null,
            $ended['queued'] => 'Cerraste sesión aquí. No pudimos confirmar el cierre en LuisML todavía; lo reintentaremos.',
            default => 'Cerraste sesión aquí. No pudimos confirmar el cierre en LuisML ni programar un reintento.',
        };

        if (config('accounts.global_logout')) {
            $url = $ended['id_token'] === null ? null : $this->centralLogoutUrl($ended['id_token'], $ended['subject'], $discovery);

            if ($url !== null) {
                return $this->leaveTo($request, $url);
            }

            SecurityLog::warning('accounts.logout.central_pending', 'logout', 'central_pending', $ended['subject']);

            return $this->signedOut($request, 'central_pending', $ended['subject'], $note);
        }

        return $this->signedOut($request, 'local', $ended['subject'], $note);
    }

    /**
     * Where to send the browser to end the central session: Accounts' end-session address with the ID Token
     * that proves who is asking, and a state that is also kept in a cookie so that the page the person lands
     * on can tell the logout really came back. Null when Accounts cannot say where that address is.
     */
    private function centralLogoutUrl(string $idToken, ?string $subject, Discovery $discovery): ?string
    {
        try {
            $endpoint = $discovery->metadata()['end_session_endpoint'] ?? null;
        } catch (Throwable) {
            return null;
        }

        if (! is_string($endpoint)) {
            return null;
        }

        $state = Str::random(20);

        Cookie::queue(Cookie::make(
            self::LOGOUT_COOKIE,
            json_encode(['state' => $state, 'subject' => $subject]),
            self::LOGOUT_COOKIE_MINUTES,
            path: '/',
            secure: request()->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        ));

        $redirect = config('accounts.post_logout_redirect');

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').http_build_query([
            'id_token_hint' => $idToken,
            'post_logout_redirect_uri' => is_string($redirect) && $redirect !== '' ? $redirect : route('accounts.signed-out'),
            'state' => $state,
        ]);
    }

    private function signedOut(Request $request, string $status, ?string $subject, ?string $note): Response
    {
        $request->session()->flash('accounts.logout', ['status' => $status, 'subject' => $subject]);

        if ($note !== null) {
            $request->session()->flash('status', $note);
        }

        return $this->leaveTo($request, route('accounts.signed-out'));
    }
}
