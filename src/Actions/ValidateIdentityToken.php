<?php

namespace LuisML\AccountsClient\Actions;

use DateInterval;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use LuisML\AccountsClient\Support\JwkToPem;
use LuisML\AccountsClient\Support\SystemClock;
use RuntimeException;
use Throwable;

class ValidateIdentityToken
{
    public function __construct(private Discovery $discovery) {}

    /**
     * Validate an ID Token: allowed algorithm, signature against the published keys, exact issuer,
     * audience, time and nonce. An access token is never accepted here. Returns the claims.
     *
     * @return array<string, mixed>
     */
    public function handle(string $jwt, string $nonce): array
    {
        $token = $this->verified($jwt);

        if (! hash_equals($nonce, (string) $token->claims()->get('nonce', ''))) {
            throw new RuntimeException('ID Token inválido.');
        }

        return $token->claims()->all();
    }

    /**
     * Validate a Back-Channel Logout token: same signature, issuer and audience rules, but it must
     * announce the logout event, carry a `jti` and the session id, and never a `nonce`.
     *
     * @return array<string, mixed>
     */
    public function handleLogoutToken(string $jwt): array
    {
        $claims = $this->verified($jwt)->claims();

        $events = $claims->get('events');

        if (! is_array($events) || ! array_key_exists('http://schemas.openid.net/event/backchannel-logout', $events)
            || $claims->has('nonce')
            || ! is_string($claims->get('jti'))
            || ! is_string($claims->get('sid'))) {
            throw new RuntimeException('Token de cierre de sesión inválido.');
        }

        return $claims->all();
    }

    private function verified(string $jwt): Plain
    {
        try {
            $token = (new Parser(new JoseEncoder))->parse($jwt);
        } catch (Throwable) {
            throw new RuntimeException('ID Token malformado.');
        }

        if (! $token instanceof Plain || $token->headers()->get('alg') !== 'RS256' || ! is_string($token->headers()->get('kid'))) {
            throw new RuntimeException('ID Token con algoritmo o kid no permitidos.');
        }

        $jwk = $this->discovery->keys()[$token->headers()->get('kid')] ?? null;

        if ($jwk === null && $this->discovery->mayRefreshKeys()) {
            $jwk = $this->discovery->keys(refresh: true)[$token->headers()->get('kid')] ?? null;
        }

        if ($jwk === null) {
            throw new RuntimeException('Clave de firma desconocida.');
        }

        $valid = (new Validator)->validate(
            $token,
            new SignedWith(new Sha256, InMemory::plainText(JwkToPem::convert($jwk))),
            new IssuedBy((string) config('accounts.issuer')),
            new PermittedFor((string) config('accounts.client_id')),
            new LooseValidAt(new SystemClock, new DateInterval('PT'.(int) config('accounts.clock_skew_seconds').'S')),
        );

        if (! $valid || ! is_string($token->claims()->get('sub'))) {
            throw new RuntimeException('ID Token inválido.');
        }

        return $token;
    }
}
