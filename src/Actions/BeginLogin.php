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
     *
     * `$options` may ask for a stronger or fresher authentication: `acr_values`, `max_age`, `prompt`.
     *
     * @param  array<string, mixed>  $options
     */
    public function handle(Request $request, ?string $intended = null, array $options = []): string
    {
        $options = $this->allowed($options);
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
            'max_age' => $options['max_age'] ?? null,
            'acr_values' => $options['acr_values'] ?? null,
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
            ...$options,
        ]);
    }

    /**
     * Only the authentication parameters this client understands ever reach Accounts.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, int|string>
     */
    private function allowed(array $options): array
    {
        $allowed = [];

        if (isset($options['max_age']) && is_numeric($options['max_age']) && (int) $options['max_age'] >= 0) {
            $allowed['max_age'] = (int) $options['max_age'];
        }

        if (isset($options['acr_values']) && is_string($options['acr_values']) && preg_match('/^[A-Za-z0-9:._\- ]{1,512}$/', $options['acr_values'])) {
            $allowed['acr_values'] = $options['acr_values'];
        }

        if (isset($options['prompt']) && in_array($options['prompt'], ['login', 'consent'], true)) {
            $allowed['prompt'] = $options['prompt'];
        }

        return $allowed;
    }

    /**
     * Consume the transaction for a state. A state is good once; unknown or expired ones return null.
     *
     * @return array{nonce: string, verifier: string, intended: ?string, max_age: ?int, acr_values: ?string, expires_at: int}|null
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
