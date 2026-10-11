<?php

namespace LuisML\AccountsClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use LuisML\AccountsClient\Actions\Discovery;
use Throwable;

class AccountsSignedOutController extends Controller
{
    /**
     * Where every sign-out ends: a public page that never sends the person anywhere on its own. It says what
     * really happened. `done` only when the central logout came back with the state this application started;
     * a close that was started and did not come back is `central_pending`, with a way to finish it.
     */
    public function __invoke(Request $request, Discovery $discovery)
    {
        $remembered = $this->remembered($request);
        $flashed = $request->session()->get('accounts.logout');
        $status = 'local';
        $subject = null;

        if ($remembered !== null) {
            $subject = $remembered['subject'];
            $returned = $request->query('state');

            if (is_string($returned) && $returned !== '' && is_string($remembered['state']) && hash_equals($remembered['state'], $returned)) {
                $status = 'done';
                Cookie::queue(Cookie::forget(AccountsLogoutController::LOGOUT_COOKIE));
            } else {
                $status = 'central_pending';
            }
        } elseif (is_array($flashed) && in_array($flashed['status'] ?? null, ['local', 'central_pending'], true)) {
            $status = $flashed['status'];
            $subject = is_string($flashed['subject'] ?? null) ? $flashed['subject'] : null;
        }

        return response()->view('accounts::signed-out', [
            'status' => $status,
            'finishUrl' => $status === 'central_pending' ? $this->finishUrl($subject, $discovery) : null,
            'note' => $request->session()->get('status'),
        ]);
    }

    /**
     * The address that lets the person finish the central close without the ID Token this application no
     * longer has: Accounts asks them to confirm, naming the account, and sends them back with a fresh state.
     * Null when Accounts cannot say where that is, in which case the page offers to try again.
     */
    private function finishUrl(?string $subject, Discovery $discovery): ?string
    {
        $state = Str::random(20);

        // Remembered even when no address can be built, so that trying again still knows a close is pending.
        Cookie::queue(Cookie::make(
            AccountsLogoutController::LOGOUT_COOKIE,
            json_encode(['state' => $state, 'subject' => $subject]),
            AccountsLogoutController::LOGOUT_COOKIE_MINUTES,
            path: '/',
            secure: request()->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        ));

        try {
            $endpoint = $discovery->metadata()['end_session_endpoint'] ?? null;
        } catch (Throwable) {
            return null;
        }

        if (! is_string($endpoint)) {
            return null;
        }

        $redirect = config('accounts.post_logout_redirect');

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').http_build_query(array_filter([
            'client_id' => config('accounts.client_id'),
            'login_hint' => $subject,
            'post_logout_redirect_uri' => is_string($redirect) && $redirect !== '' ? $redirect : route('accounts.signed-out'),
            'state' => $state,
        ]));
    }

    /**
     * @return array{state: mixed, subject: ?string}|null
     */
    private function remembered(Request $request): ?array
    {
        $cookie = $request->cookie(AccountsLogoutController::LOGOUT_COOKIE);
        $decoded = is_string($cookie) ? json_decode($cookie, true) : null;

        if (! is_array($decoded)) {
            return null;
        }

        return ['state' => $decoded['state'] ?? null, 'subject' => is_string($decoded['subject'] ?? null) ? $decoded['subject'] : null];
    }
}
