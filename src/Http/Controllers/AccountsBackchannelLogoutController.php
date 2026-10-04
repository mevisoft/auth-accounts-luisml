<?php

namespace LuisML\AccountsClient\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use LuisML\AccountsClient\Actions\ValidateIdentityToken;
use Throwable;

class AccountsBackchannelLogoutController extends Controller
{
    public static function cacheKey(string $sid): string
    {
        return 'accounts-client:ended-session:'.hash('sha256', $sid);
    }

    /**
     * OIDC Back-Channel Logout: Accounts says one of its sessions ended. The session id is
     * remembered for as long as a local session can live, and `EnsureAccountsAccess` ends any local
     * session carrying it on its next request. Needs a cache shared by every server of the app.
     */
    public function __invoke(Request $request, ValidateIdentityToken $validate): JsonResponse
    {
        try {
            $claims = $validate->handleLogoutToken((string) $request->input('logout_token'));
        } catch (Throwable) {
            return $this->answer(['error' => 'invalid_request'], 400);
        }

        if (! Cache::add('accounts-client:logout-jti:'.hash('sha256', $claims['jti']), true, 3600)) {
            return $this->answer(['error' => 'invalid_request'], 400);
        }

        Cache::put(self::cacheKey($claims['sid']), true, now()->addMinutes((int) config('session.lifetime', 120)));

        return $this->answer([], 200);
    }

    /**
     * @param  array<string, string>  $body
     */
    private function answer(array $body, int $status): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store']);
    }
}
