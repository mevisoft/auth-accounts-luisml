<?php

namespace LuisML\AccountsClient\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LuisML\AccountsClient\AccountsSession;
use LuisML\AccountsClient\Identity;
use RuntimeException;

class CompleteLogin
{
    public function __construct(
        private Discovery $discovery,
        private AccountsHttp $http,
        private ValidateIdentityToken $validate,
    ) {}

    /**
     * Redeem the code, validate the ID Token, confirm the subject with UserInfo, link the local
     * user by (issuer, sub), regenerate the session and keep the tokens server-side.
     *
     * @param  array{nonce: string, verifier: string, max_age?: ?int}  $transaction
     */
    public function handle(Request $request, string $code, array $transaction): Authenticatable
    {
        $startedAt = now()->getTimestamp();
        $metadata = $this->discovery->metadata();

        $tokens = $this->http->client()->asForm()->post($metadata['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('accounts.redirect'),
            'client_id' => config('accounts.client_id'),
            'client_secret' => config('accounts.client_secret'),
            'code_verifier' => $transaction['verifier'],
        ]);

        if (! $tokens->successful() || ! is_string($tokens->json('access_token')) || ! is_string($tokens->json('id_token'))) {
            throw new RuntimeException('Accounts rechazó el canje del código.');
        }

        $claims = $this->validate->handle($tokens->json('id_token'), $transaction['nonce']);

        $this->assertFreshEnough($claims, $transaction['max_age'] ?? null);

        $info = $this->http->client()->withToken($tokens->json('access_token'))->get($metadata['userinfo_endpoint']);

        if (! $info->successful() || $info->json('sub') !== $claims['sub']) {
            throw new RuntimeException('UserInfo no corresponde al ID Token.');
        }

        $identity = new Identity(
            issuer: (string) config('accounts.issuer'),
            subject: $claims['sub'],
            name: $info->json('name'),
            email: $info->json('email'),
            emailVerified: (bool) $info->json('email_verified', false),
            roles: array_values(array_filter((array) $info->json('roles', []), 'is_string')),
        );

        $user = app((string) config('accounts.user_resolver'))($identity)
            ?? throw new RuntimeException('La aplicación rechazó la identidad.');

        Auth::login($user);
        $request->session()->regenerate();

        $payload = [
            'access_token' => $tokens->json('access_token'),
            'refresh_token' => $tokens->json('refresh_token'),
            'expires_in' => (int) $tokens->json('expires_in'),
        ];

        $session = AccountsSession::for($request->session());
        $session->store($payload, 0, null, $identity->subject, $claims, $tokens->json('id_token'));
        $session->validated($startedAt, null);

        return $user;
    }

    /**
     * When `max_age` was asked for, the ID Token must say when the person authenticated, and recently.
     *
     * @param  array<string, mixed>  $claims
     */
    private function assertFreshEnough(array $claims, ?int $maxAge): void
    {
        if ($maxAge === null) {
            return;
        }

        $authTime = $claims['auth_time'] ?? null;

        if (! is_numeric($authTime) || now()->getTimestamp() - (int) $authTime > $maxAge + (int) config('accounts.clock_skew_seconds')) {
            throw new RuntimeException('Accounts no confirmó una autenticación reciente.');
        }
    }
}
