<?php

namespace LuisML\AccountsClient\Actions;

use Illuminate\Support\Facades\Cache;
use LuisML\AccountsClient\AccountsUnavailable;

class Discovery
{
    private const DISCOVERY_TTL = 86_400;

    private const JWKS_TTL = 300;

    private const JWKS_REFRESH_COOLDOWN = 60;

    private const DISCOVERY_PATH = '/.well-known/openid-configuration';

    public function __construct(private AccountsHttp $http) {}

    /**
     * Get the provider metadata.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        $issuer = $this->issuer();

        return Cache::remember(
            $this->cacheKey('discovery'),
            self::DISCOVERY_TTL,
            function () use ($issuer): array {
                $document = $this->fetch(self::DISCOVERY_PATH);

                if (($document['issuer'] ?? null) !== $issuer) {
                    throw new AccountsUnavailable(
                        'El emisor del descubrimiento no coincide con el configurado.'
                    );
                }

                foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri'] as $endpoint) {
                    $url = $document[$endpoint] ?? null;

                    if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
                        || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
                        || parse_url($url, PHP_URL_FRAGMENT) !== null
                        || parse_url($url, PHP_URL_USER) !== null) {
                        throw new AccountsUnavailable('El documento de descubrimiento contiene un endpoint inválido.');
                    }
                }

                return $document;
            },
        );
    }

    /**
     * Get the provider public keys indexed by kid.
     *
     * @return array<string, array<string, mixed>>
     */
    public function keys(bool $refresh = false): array
    {
        $cacheKey = $this->cacheKey('jwks');

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember(
            $cacheKey,
            self::JWKS_TTL,
            function (): array {
                $document = $this->fetch(
                    (string) $this->metadata()['jwks_uri']
                );

                $keys = $document['keys'] ?? null;

                if (! is_array($keys)) {
                    throw new AccountsUnavailable(
                        'Accounts devolvió un documento JWKS inválido.'
                    );
                }

                return collect($keys)
                    ->filter(
                        fn (mixed $key): bool => is_array($key)
                            && isset($key['kid'])
                            && is_string($key['kid'])
                    )
                    ->keyBy('kid')
                    ->all();
            },
        );
    }

    /**
     * Determine whether the JWKS may be refreshed.
     */
    public function mayRefreshKeys(): bool
    {
        return Cache::add(
            $this->cacheKey('jwks-refresh'),
            true,
            self::JWKS_REFRESH_COOLDOWN,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $url): array
    {
        $response = $this->http->get($url);

        if (! $response->successful()) {
            throw new AccountsUnavailable(
                "Accounts respondió {$response->status()}."
            );
        }

        $document = $response->json();

        if (! is_array($document)) {
            throw new AccountsUnavailable(
                'Accounts devolvió una respuesta JSON inválida.'
            );
        }

        return $document;
    }

    private function issuer(): string
    {
        return (string) config('accounts.issuer');
    }

    private function cacheKey(string $type): string
    {
        return "accounts-client:{$type}:".hash('sha256', $this->issuer());
    }
}
