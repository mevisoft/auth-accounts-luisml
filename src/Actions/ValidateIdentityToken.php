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

        if (! $valid || ! hash_equals($nonce, (string) $token->claims()->get('nonce', '')) || ! is_string($token->claims()->get('sub'))) {
            throw new RuntimeException('ID Token inválido.');
        }

        return $token->claims()->all();
    }
}
