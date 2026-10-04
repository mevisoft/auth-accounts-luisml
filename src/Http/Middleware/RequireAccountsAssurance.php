<?php

namespace LuisML\AccountsClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LuisML\AccountsClient\AccountsSession;
use LuisML\AccountsClient\Support\AssuranceLevels;
use Symfony\Component\HttpFoundation\Response;

class RequireAccountsAssurance
{
    private const RETRY_SECONDS = 300;

    /**
     * Step-up for sensitive operations: `accounts.step-up:acr=urn:accounts:acr:phr,max_age=300`.
     * When the sign-in is weaker or older than asked, the person is sent to Accounts to raise it and
     * comes back to this request. Accounts may not be able to raise it (the account has no passkey);
     * a second attempt for the same address is refused instead of looping.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$requirements): Response
    {
        $required = $this->parse($requirements);
        $state = AccountsSession::for($request->session());

        if ($this->satisfied($state, $required)) {
            return $next($request);
        }

        $key = 'accounts.step_up.'.sha1($request->fullUrl());
        $attemptedAt = $request->session()->get($key);

        if ($attemptedAt !== null && now()->getTimestamp() - (int) $attemptedAt < self::RETRY_SECONDS) {
            $request->session()->forget($key);

            return $this->unavailable($request, $required);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Esta operación exige una autenticación más fuerte.', 'code' => 'accounts_step_up_required', ...$required], 401);
        }

        $request->session()->put($key, now()->getTimestamp());
        $request->session()->put('url.intended', $request->isMethod('GET') ? $request->fullUrl() : url()->previous());

        return redirect()->route('accounts.login', array_filter([
            'acr_values' => $required['acr'] ?? null,
            'max_age' => $required['max_age'] ?? null,
        ], fn ($value): bool => $value !== null));
    }

    /**
     * @param  array<int, string>  $requirements  `key=value` pairs
     * @return array{acr?: string, max_age?: int}
     */
    private function parse(array $requirements): array
    {
        $required = [];

        foreach ($requirements as $requirement) {
            [$key, $value] = array_pad(explode('=', $requirement, 2), 2, null);

            if ($key === 'acr' && $value !== null && $value !== '') {
                $required['acr'] = $value;
            }

            if ($key === 'max_age' && is_numeric($value)) {
                $required['max_age'] = (int) $value;
            }
        }

        return $required;
    }

    /**
     * @param  array{acr?: string, max_age?: int}  $required
     */
    private function satisfied(AccountsSession $state, array $required): bool
    {
        if (! $state->exists()) {
            return false;
        }

        if (isset($required['acr']) && ! AssuranceLevels::satisfies($state->acr(), $required['acr'])) {
            return false;
        }

        return ! isset($required['max_age'])
            || ($state->authTime() !== null && now()->getTimestamp() - $state->authTime() <= $required['max_age']);
    }

    /**
     * @param  array{acr?: string, max_age?: int}  $required
     */
    private function unavailable(Request $request, array $required): Response
    {
        $message = 'No pudimos elevar tu autenticación a la que pide esta operación. Revisa que tu cuenta tenga el método necesario (por ejemplo una passkey).';

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'code' => 'accounts_assurance_unavailable', ...$required], 403)
            : response($message, 403);
    }
}
