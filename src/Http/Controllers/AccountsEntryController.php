<?php

namespace LuisML\AccountsClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LuisML\AccountsClient\Http\Controllers\Concerns\LeavesTheApplication;

class AccountsEntryController extends Controller
{
    use LeavesTheApplication;

    /**
     * Seconds within which a second visit means the automatic sign-in did not work.
     */
    private const LOOP_WINDOW = 30;

    /**
     * The `login` address: guests are sent straight to Accounts. If they come back here right away
     * (the callback failed and the protected page bounced them), a page with a manual button is
     * shown instead of redirecting again, so a failing Accounts never causes a redirect loop.
     */
    public function __invoke(Request $request)
    {
        $lastAttempt = (int) $request->session()->get('accounts.auto_login_at', 0);

        if (now()->getTimestamp() - $lastAttempt < self::LOOP_WINDOW) {
            $request->session()->forget('accounts.auto_login_at');

            return response()->view('accounts::login', ['error' => $request->session()->get('accounts.error')]);
        }

        $request->session()->put('accounts.auto_login_at', now()->getTimestamp());

        return $this->leaveTo($request, route('accounts.login', $request->only(['acr_values', 'max_age', 'prompt'])));
    }
}
