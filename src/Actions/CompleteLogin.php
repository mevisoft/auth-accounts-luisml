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

        $tokens = $this->http->post($metadata['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('accounts.redirect'),
            'code_verifier' => $transaction['verifier'],
        ]);

        if (! $tokens->successful() || ! is_string($tokens->json('access_token')) || ! is_string($tokens->json('id_token'))) {
            throw new RuntimeException('Accounts rechazó el canje del código: '.$tokens->body());
        }

        $payload = TokenResponse::tokens($tokens->json());

        $claims = $this->validate->handle($tokens->json('id_token'), $transaction['nonce']);

        $this->assertFreshEnough($claims, $transaction['max_age'] ?? null);

        $info = $this->http->get($metadata['userinfo_endpoint'], $payload['access_token']);

        if (! $info->successful() || $info->json('sub') !== $claims['sub']) {
            throw new RuntimeException('UserInfo no corresponde al ID Token: '.$info->body());
        }

        $payload['expires_in'] -= now()->getTimestamp() - $startedAt;

        if ($payload['expires_in'] <= 0) {
            throw new RuntimeException('El token de acceso caducó durante el inicio de sesión.');
        }

        $identity = Identity::fromUserInfo(
            (string) config('accounts.issuer'), $claims['sub'], $info->json(),
        );

        $user = app((string) config('accounts.user_resolver'))($identity)
            ?? throw new RuntimeException('La aplicación rechazó la identidad.');

        $session = AccountsSession::for($request->session());
        $session->store($payload, 0, null, $identity->subject, $claims, $tokens->json('id_token'));
        $session->validated($startedAt, null);

        Auth::login($user);
        $request->session()->regenerate();

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
