<?php

namespace LuisML\AccountsClient\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BeginLogin
{
    public const SESSION_KEY = 'accounts.transactions';

    public function __construct(private Discovery $discovery) {}

    /**
     * Start a login: one transaction per state (so several tabs never mix), each with its own nonce
     * and PKCE verifier, valid for a few minutes and consumable once. Returns Accounts' authorize URL.
     */
    public function handle(Request $request, ?string $intended = null): string
    {
        $metadata = $this->discovery->metadata();

        $state = Str::random(40);
        $verifier = Str::random(64);
        $nonce = Str::random(40);

        $transactions = collect($request->session()->get(self::SESSION_KEY, []))
            ->filter(fn (array $transaction): bool => $transaction['expires_at'] > now()->getTimestamp())
            ->take(-9)
            ->all();

        $transactions[$state] = [
            'nonce' => $nonce,
            'verifier' => $verifier,
            'intended' => $intended,
            'expires_at' => now()->getTimestamp() + (int) config('accounts.transaction_minutes') * 60,
        ];

        $request->session()->put(self::SESSION_KEY, $transactions);

        return $metadata['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('accounts.client_id'),
            'redirect_uri' => config('accounts.redirect'),
            'scope' => implode(' ', (array) config('accounts.scopes')),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Consume the transaction for a state. A state is good once; unknown or expired ones return null.
     *
     * @return array{nonce: string, verifier: string, intended: ?string, expires_at: int}|null
     */
    public function consume(Request $request, string $state): ?array
    {
        $transactions = $request->session()->get(self::SESSION_KEY, []);
        $transaction = $transactions[$state] ?? null;

        unset($transactions[$state]);
        $request->session()->put(self::SESSION_KEY, $transactions);

        return $transaction !== null && $transaction['expires_at'] > now()->getTimestamp() ? $transaction : null;
    }
}
