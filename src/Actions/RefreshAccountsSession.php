<?php

namespace LuisML\AccountsClient\Actions;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use LuisML\AccountsClient\AccountsSession;
use LuisML\AccountsClient\AccountsUnavailable;

class RefreshAccountsSession
{
    public function __construct(private Discovery $discovery, private AccountsHttp $http) {}

    /**
     * Exchange the refresh token under a mutual-exclusion lock so concurrent requests never redeem it
     * twice. Returns false when a new authorization is required: Accounts refused it, or the outcome
     * is ambiguous (a consumed token cannot be reused).
     */
    public function handle(Session $session): bool
    {
        $lockSeconds = max(10, 2 * (int) config('accounts.http.timeout') + 5);
        $lock = Cache::lock('accounts-client:refresh:'.$session->getId(), $lockSeconds);

        try {
            $lock->block((int) config('accounts.refresh_lock_wait_seconds'));
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return false;
        }

        try {
            $state = AccountsSession::for($session);

            if (! $state->accessExpired()) {
                return true;
            }

            $refreshToken = $state->refreshToken();

            if ($refreshToken === null) {
                return false;
            }

            // A lock alone cannot update session snapshots already loaded by other requests.
            // Share the encrypted result for this token generation, including ambiguous failures.
            $resultKey = 'accounts-client:refresh-result:'.hash('sha256', json_encode([
                config('accounts.issuer'), config('accounts.client_id'), $session->getId(),
                $refreshToken, $state->accessToken(),
            ]));
            $result = Cache::get($resultKey);

            if ($result !== null) {
                return $this->applyResult($state, $result);
            }

            try {
                $endpoint = $this->discovery->metadata()['token_endpoint'];
                $ttl = max(300, (int) config('session.lifetime', 120) * 60, $lockSeconds * 2);

                // A worker that dies after sending the token must not let another worker reuse it.
                Cache::put($resultKey, false, $ttl);
                $startedAt = now()->getTimestamp();
                $response = $this->http->post($endpoint, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);

                if (! $response->successful()) {
                    $state->forget();

                    return false;
                }

                $tokens = TokenResponse::tokens($response->json(), $refreshToken);
                $tokens['expires_at'] = $startedAt + $tokens['expires_in'];
                $result = Crypt::encryptString(json_encode($tokens, JSON_THROW_ON_ERROR));
                Cache::put($resultKey, $result, $ttl);
            } catch (\Throwable) {
                $state->forget();

                return false;
            }

            return $this->applyResult($state, $result);
        } catch (AccountsUnavailable) {
            return false;
        } finally {
            $lock->release();
        }
    }

    private function applyResult(AccountsSession $state, mixed $result): bool
    {
        if (is_string($result)) {
            $tokens = json_decode(Crypt::decryptString($result), true, flags: JSON_THROW_ON_ERROR);
            $tokens['expires_in'] = $tokens['expires_at'] - now()->getTimestamp();

            if ($tokens['expires_in'] > 0) {
                $state->rotate($tokens);

                return true;
            }
        }

        $state->forget();

        return false;
    }
}
