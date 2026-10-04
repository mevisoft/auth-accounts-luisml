<?php

namespace LuisML\AccountsClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LuisML\AccountsClient\AccountsUnavailable;
use LuisML\AccountsClient\Actions\BeginLogin;
use LuisML\AccountsClient\Http\Controllers\Concerns\LeavesTheApplication;

class AccountsLoginController extends Controller
{
    use LeavesTheApplication;

    public function __invoke(Request $request, BeginLogin $begin)
    {
        $intended = $request->session()->get('url.intended');
        $path = is_string($intended) ? parse_url($intended, PHP_URL_PATH).(($query = parse_url($intended, PHP_URL_QUERY)) ? '?'.$query : '') : null;

        try {
            $url = $begin->handle(
                $request,
                is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : null,
                $request->only(['acr_values', 'max_age', 'prompt']),
            );
        } catch (AccountsUnavailable) {
            return response()->view('accounts::unavailable', [
                'message' => 'No pudimos contactar a LuisML para iniciar sesión. Inténtalo de nuevo en unos segundos.',
                'retryUrl' => route('accounts.login'),
                'safeToRetry' => true,
            ], 503, ['Retry-After' => '10']);
        }

        return $this->leaveTo($request, $url);
    }
}
