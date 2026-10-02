<?php

namespace LuisML\AccountsClient\Actions;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
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
        $lock = Cache::lock('accounts-client:refresh:'.$session->getId(), 10);

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

            try {
                $response = $this->http->client()->asForm()->post($this->discovery->metadata()['token_endpoint'], [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => config('accounts.client_id'),
                    'client_secret' => config('accounts.client_secret'),
                ]);
            } catch (\Throwable) {
                $state->forget();

                return false;
            }

            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                $state->forget();

                return false;
            }

            $state->rotate([
                'access_token' => $response->json('access_token'),
                'refresh_token' => $response->json('refresh_token'),
                'expires_in' => (int) $response->json('expires_in'),
            ]);

            return true;
        } catch (AccountsUnavailable) {
            return false;
        } finally {
            $lock->release();
        }
    }
}
