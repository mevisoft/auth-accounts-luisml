<?php

namespace LuisML\AccountsClient\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use LuisML\AccountsClient\AccountsSession;
use LuisML\AccountsClient\Jobs\RevokeAccountsAccess;
use Throwable;

class EndLocalAccess
{
    public function __construct(private AccountsHttp $http) {}

    /**
     * End this application's access for the person in this browser: the local session goes first and always,
     * then Accounts is asked to revoke the access this application held. If that cannot be confirmed now, a
     * durable job retries it. Nothing here touches the person's central session or other applications.
     *
     * @return array{id_token: ?string, subject: ?string, confirmed: bool, queued: bool}
     */
    public function handle(Request $request): array
    {
        $state = AccountsSession::for($request->session());
        $token = $state->refreshToken() ?? $state->accessToken();
        $idToken = $state->idToken();
        $subject = $state->subject();

        $state->forget();
        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $confirmed = $token === null;
        $queued = false;

        if ($token !== null) {
            try {
                $confirmed = $this->http->post('/oauth/revoke', ['token' => $token])->successful();
            } catch (Throwable) {
                $confirmed = false;
            }

            if (! $confirmed) {
                try {
                    RevokeAccountsAccess::dispatch(Crypt::encryptString($token), $subject, now()->getTimestamp());
                    $queued = config('queue.default') !== 'sync';
                    $confirmed = ! $queued;
                } catch (Throwable $exception) {
                    Log::warning('No se pudo encolar la revocación de Accounts.', ['reason' => $exception::class]);
                }
            }
        }

        return ['id_token' => $idToken, 'subject' => $subject, 'confirmed' => $confirmed, 'queued' => $queued];
    }
}
