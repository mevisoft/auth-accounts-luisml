<?php

namespace LuisML\AccountsClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LuisML\AccountsClient\AccountsSession;
use LuisML\AccountsClient\Actions\AccountsHttp;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ReportAccountsActivity
{
    public function __construct(private AccountsHttp $http) {}

    /**
     * Tell Accounts about real, person-initiated activity (page navigation and submitted forms).
     * Polling, prefetching, background refreshes and failed requests never count, reports are
     * grouped, and a failed report never extends anything locally.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (Auth::check() && $this->isPersonInitiated($request, $response)) {
            $this->report($request);
        }

        return $response;
    }

    private function isPersonInitiated(Request $request, Response $response): bool
    {
        return ! $request->isMethod('HEAD')
            && ! $request->isMethod('OPTIONS')
            && $response->getStatusCode() < 400
            && ! $request->hasHeader('X-Accounts-Passive')
            && ! $request->hasHeader('X-Inertia-Partial-Component')
            && ! in_array(strtolower((string) ($request->header('Purpose') ?? $request->header('Sec-Purpose'))), ['prefetch', 'prefetch;prerender'], true);
    }

    private function report(Request $request): void
    {
        $state = AccountsSession::for($request->session());

        if (! $state->exists() || $state->accessToken() === null) {
            return;
        }

        $nearEnd = $state->sessionExpiresAt() !== null && $state->sessionExpiresAt() - now()->getTimestamp() < 60;

        if (! $nearEnd && now()->getTimestamp() - $state->lastReportAt() < (int) config('accounts.activity_interval_seconds')) {
            return;
        }

        $state->markReported();
        $startedAt = now()->getTimestamp();

        try {
            $response = $this->http->post('/api/v1/session-activity', [], $state->accessToken());
        } catch (Throwable) {
            return;
        }

        if ($response->successful() && $response->json('data.session_expires_at') !== null) {
            $state->validated($startedAt, (int) $response->json('data.session_expires_at'));
        }
    }
}
