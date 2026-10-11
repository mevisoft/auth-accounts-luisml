<?php

namespace LuisML\AccountsClient\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use LuisML\AccountsClient\Actions\AccountsHttp;
use RuntimeException;

class RevokeAccountsAccess implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /**
     * How long the note that an account signed in again is kept: longer than the retries can last.
     */
    public const LOGIN_MARKER_MINUTES = 40;

    /**
     * @param  string  $encryptedToken  the token, encrypted: it must not sit in the queue in clear
     * @param  string|null  $subject  public identifier of the account whose access this revokes
     * @param  int|null  $queuedAt  when the revocation was queued
     */
    public function __construct(public string $encryptedToken, public ?string $subject = null, public ?int $queuedAt = null) {}

    /**
     * Where `CompleteLogin` notes the time an account signed in again in this application.
     */
    public static function loginMarkerKey(string $subject): string
    {
        return 'accounts-client:login-after:'.hash('sha256', json_encode([config('accounts.issuer'), config('accounts.client_id'), $subject]));
    }

    /**
     * Idempotent: revoking a token that is already revoked still succeeds in Accounts. It is skipped when the
     * same account signed in again after it was queued: Accounts revokes the whole application session behind
     * a token, and that session may by now be the one the person has just opened.
     */
    public function handle(AccountsHttp $http): void
    {
        if ($this->subject !== null && $this->queuedAt !== null
            && (int) Cache::get(self::loginMarkerKey($this->subject), 0) >= $this->queuedAt) {
            return;
        }

        $response = $http->post('/oauth/revoke', ['token' => Crypt::decryptString($this->encryptedToken)]);

        if (! $response->successful()) {
            // Throwing uses the queue's backoff and also reports failure with the sync driver.
            throw new RuntimeException('Accounts rechazó la revocación del acceso.');
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
