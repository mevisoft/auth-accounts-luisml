<?php

namespace LuisML\AccountsClient\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use LuisML\AccountsClient\Actions\AccountsHttp;

class RevokeAccountsAccess implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /**
     * @param  string  $encryptedToken  the token, encrypted: it must not sit in the queue in clear
     */
    public function __construct(public string $encryptedToken) {}

    /**
     * Idempotent: revoking a token that is already revoked still succeeds in Accounts.
     */
    public function handle(AccountsHttp $http): void
    {
        $response = $http->post('/oauth/revoke', ['token' => Crypt::decryptString($this->encryptedToken)]);

        if (! $response->successful()) {
            $this->release(30);
        }
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }
}
