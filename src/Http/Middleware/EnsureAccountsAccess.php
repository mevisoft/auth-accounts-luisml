<?php

namespace LuisML\AccountsClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LuisML\AccountsClient\AccountsSession;
use LuisML\AccountsClient\AccountsUnavailable;
use LuisML\AccountsClient\Actions\AccountsHttp;
use LuisML\AccountsClient\Actions\RefreshAccountsSession;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountsAccess
{
    public function __construct(private AccountsHttp $http, private RefreshAccountsSession $refresh) {}

    /**
     * Every protected operation passes through here. A validation is good for at most the configured
     * window (bounded by the token and the central session); after that Accounts must confirm the
     * access again. If it cannot, the operation is blocked — nothing is replayed and the local
     * session is kept so the person can simply retry.
     *
     * With `lenient`, people who signed in some other way (no Accounts session) pass through
     * untouched, so an app can keep its own login and still control Accounts-based sessions.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $mode = 'strict'): Response
    {
        $state = AccountsSession::for($request->session());

        if ($mode === 'lenient' && ! $state->exists()) {
            return $next($request);
        }

        if (! Auth::check() || ! $state->exists()) {
            return $this->toLogin($request);
        }

        if ($state->validatedUntil() > now()->getTimestamp()) {
            return $next($request);
        }

        if ($state->accessExpired() && ! $this->refresh->handle($request->session())) {
            return $this->reauthorize($request);
        }

        $startedAt = now()->getTimestamp();

        try {
            $response = $this->http->post('/oauth/introspect', ['token' => $state->accessToken()]);
        } catch (AccountsUnavailable) {
            return $this->unavailable($request);
        }

        if ($response->status() === 401) {
            return $this->unavailable($request);
        }

        if (! $response->json('active')) {
            return $this->reauthorize($request);
        }

        $state->validated($startedAt, $response->json('accounts_session_expires_at'));

        return $next($request);
    }

    private function toLogin(Request $request): Response
    {
        return $request->expectsJson()
            ? response()->json(['message' => 'Inicia sesión con LuisML.'], 401)
            : redirect()->guest(route('accounts.login'));
    }

    /**
     * Accounts says the access ended (revoked, expired, no longer eligible): end the local session too.
     */
    private function reauthorize(Request $request): Response
    {
        $intended = $request->isMethod('GET') ? $request->fullUrl() : url()->previous();

        AccountsSession::for($request->session())->forget();
        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Tu sesión terminó. Inicia sesión de nuevo.', 'code' => 'accounts_session_ended'], 401);
        }

        $request->session()->put('url.intended', $intended);

        return redirect()->route('accounts.login');
    }

    private function unavailable(Request $request): Response
    {
        $message = 'No pudimos confirmar tu acceso con LuisML. Tu sesión sigue abierta; inténtalo de nuevo en unos segundos.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'code' => 'accounts_unavailable', 'retry' => true], 503, ['Retry-After' => '10']);
        }

        return response()->view('accounts::unavailable', ['message' => $message, 'retryUrl' => $request->fullUrl(), 'safeToRetry' => $request->isMethod('GET')], 503, ['Retry-After' => '10']);
    }
}
