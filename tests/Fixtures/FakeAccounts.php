<?php

namespace LuisML\AccountsClient\Tests\Fixtures;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

/**
 * A stand-in for Accounts: discovery, JWKS, token, UserInfo, introspection, revocation and
 * activity endpoints, with switches to make each one fail the way a real outage would.
 */
class FakeAccounts
{
    public string $kid = 'key-1';

    public bool $down = false;

    public bool $introspectionActive = true;

    public ?int $introspectionStatus = null;

    public bool $refreshFails = false;

    public bool $refreshTimesOut = false;

    public bool $revokeFails = false;

    public bool $activityFails = false;

    public int $sessionExpiresIn = 1800;

    public string $subject = 'subject-1';

    /** @var list<string> */
    public array $calls = [];

    private $privateKey;

    private ?string $privatePem = null;

    private string $publicPem;

    /** @var array<string, string> */
    public array $lastNonce = [];

    public function __construct()
    {
        $this->privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($this->privateKey, $this->privatePem);
        $this->publicPem = openssl_pkey_get_details($this->privateKey)['key'];
    }

    public function install(): void
    {
        $issuer = 'https://accounts.example.test';

        Http::fake(function (Request $request) use ($issuer) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = $request->method().' '.$path;

            if ($this->down) {
                throw new ConnectionException('Accounts caído');
            }

            return match (true) {
                $path === '/.well-known/openid-configuration' => Http::response([
                    'issuer' => $issuer,
                    'authorization_endpoint' => $issuer.'/oauth/authorize',
                    'token_endpoint' => $issuer.'/oauth/token',
                    'userinfo_endpoint' => $issuer.'/oauth/userinfo',
                    'jwks_uri' => $issuer.'/oauth/jwks',
                ]),
                $path === '/oauth/jwks' => Http::response(['keys' => [$this->jwk()]]),
                $path === '/oauth/token' => $this->token($request),
                $path === '/oauth/userinfo' => Http::response(['sub' => $this->subject, 'name' => 'Ana Pérez', 'email' => 'ana@example.test', 'email_verified' => true]),
                $path === '/oauth/introspect' && $this->introspectionStatus !== null => Http::response(['error' => 'invalid_client'], $this->introspectionStatus),
                $path === '/oauth/introspect' => Http::response($this->introspectionActive
                    ? ['active' => true, 'sub' => $this->subject, 'accounts_session_expires_at' => now()->addSeconds($this->sessionExpiresIn)->getTimestamp()]
                    : ['active' => false]),
                $path === '/oauth/revoke' => $this->revokeFails ? Http::response([], 500) : Http::response([]),
                $path === '/api/v1/session-activity' => $this->activityFails
                    ? Http::response([], 503)
                    : Http::response(['data' => ['session_expires_at' => now()->addSeconds($this->sessionExpiresIn)->getTimestamp()]]),
                default => Http::response([], 404),
            };
        });
    }

    public function called(string $call): int
    {
        return count(array_filter($this->calls, fn (string $made) => $made === $call));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function idToken(string $nonce, array $overrides = [], ?string $alg = null): string
    {
        $claims = [
            'iss' => 'https://accounts.example.test',
            'aud' => 'client-id-1',
            'sub' => $this->subject,
            'nonce' => $nonce,
            'iat' => now()->getTimestamp(),
            'exp' => now()->addMinutes(5)->getTimestamp(),
            ...$overrides,
        ];

        $header = ['typ' => 'JWT', 'alg' => $alg ?? 'RS256', 'kid' => $overrides['kid'] ?? $this->kid];
        unset($claims['kid']);

        $encode = fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        $signingInput = $encode($header).'.'.$encode($claims);

        if (($alg ?? 'RS256') === 'RS256') {
            openssl_sign($signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        } else {
            $signature = hash_hmac('sha256', $signingInput, $this->publicPem, true);
        }

        return $signingInput.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    /**
     * Sign with a key that is not the published one.
     */
    public function forgedIdToken(string $nonce): string
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $pem);

        $config = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText($pem), InMemory::plainText(openssl_pkey_get_details($other)['key']));

        return $config->builder()
            ->issuedBy('https://accounts.example.test')->permittedFor('client-id-1')->relatedTo($this->subject)
            ->withClaim('nonce', $nonce)->withHeader('kid', $this->kid)
            ->issuedAt(new \DateTimeImmutable)->expiresAt(new \DateTimeImmutable('+5 minutes'))
            ->getToken($config->signer(), $config->signingKey())->toString();
    }

    /** @var Closure|null */
    public ?Closure $tokenResponse = null;

    private function token(Request $request)
    {
        $data = $request->data();

        if ($data['grant_type'] === 'refresh_token') {
            if ($this->refreshTimesOut) {
                throw new ConnectionException('timeout');
            }

            return $this->refreshFails
                ? Http::response(['error' => 'invalid_grant'], 400)
                : Http::response(['token_type' => 'Bearer', 'expires_in' => 300, 'access_token' => 'access-'.bin2hex(random_bytes(4)), 'refresh_token' => 'refresh-'.bin2hex(random_bytes(4))]);
        }

        if ($this->tokenResponse !== null) {
            return ($this->tokenResponse)($data);
        }

        return Http::response([
            'token_type' => 'Bearer',
            'expires_in' => 300,
            'access_token' => 'access-initial',
            'refresh_token' => 'refresh-initial',
            'id_token' => $this->idToken($this->lastNonce['nonce'] ?? ''),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function jwk(): array
    {
        $details = openssl_pkey_get_details($this->privateKey);
        $b64 = fn (string $binary): string => rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');

        return ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $this->kid, 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])];
    }
}
