<?php

namespace LuisML\AccountsClient\Actions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use LuisML\AccountsClient\AccountsUnavailable;

class Discovery
{
    public function __construct(private AccountsHttp $http) {}

    /**
     * The provider metadata, cached. The issuer it declares must equal the configured one exactly.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return Cache::remember('accounts-client:discovery:'.md5((string) config('accounts.issuer')), 3600, function (): array {
            $issuer = rtrim((string) config('accounts.issuer'), '/');
            $document = $this->fetch($issuer.'/.well-known/openid-configuration');

            if (rtrim((string) ($document['issuer'] ?? ''), '/') !== $issuer) {
                throw new AccountsUnavailable('El emisor del descubrimiento no coincide con el configurado.');
            }

            return $document;
        });
    }

    /**
     * Public keys by kid. An unknown kid refreshes the keys once, at most once a minute.
     *
     * @return array<string, array<string, string>>
     */
    public function keys(bool $refresh = false): array
    {
        $cacheKey = 'accounts-client:jwks:'.md5((string) config('accounts.issuer'));

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 300, function (): array {
            $document = $this->fetch((string) $this->metadata()['jwks_uri']);

            return collect($document['keys'] ?? [])->keyBy('kid')->all();
        });
    }

    public function mayRefreshKeys(): bool
    {
        return Cache::add('accounts-client:jwks-refresh:'.md5((string) config('accounts.issuer')), 1, 60);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $url): array
    {
        try {
            $response = $this->http->client()->get($url);
        } catch (ConnectionException $exception) {
            throw new AccountsUnavailable('Accounts no respondió.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new AccountsUnavailable("Accounts respondió {$response->status()}.");
        }

        return (array) $response->json();
    }
}
