<?php

namespace LuisML\AccountsClient\Actions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LuisML\AccountsClient\AccountsUnavailable;

class AccountsHttp
{
    public function client(): PendingRequest
    {
        return Http::connectTimeout((int) config('accounts.http.connect_timeout'))
            ->timeout((int) config('accounts.http.timeout'))
            ->withoutRedirecting()
            ->acceptJson()
            ->asForm()
            ->baseUrl(rtrim((string) config('accounts.issuer'), '/'));
    }

    public function get(string $url, ?string $bearer = null): Response
    {
        $request = $this->client();

        if ($bearer !== null) {
            $request->withToken($bearer);
        }

        try {
            return $request->get($url);
        } catch (ConnectionException $exception) {
            throw new AccountsUnavailable('Accounts no respondió.', previous: $exception);
        }
    }

    /**
     * Send a POST request authenticated with the client's credentials
     * using client_secret_post.
     *
     * Network failures, rate limiting, and 5xx responses are treated as
     * service unavailability and are never retried automatically.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AccountsUnavailable
     */
    public function post(string $path, array $data = [], ?string $bearer = null): Response
    {
        $request = $this->client();

        if ($bearer !== null) {
            $request->withToken($bearer);
        }

        try {
            $response = $request->post($path, [
                'client_id' => config('accounts.client_id'),
                'client_secret' => config('accounts.client_secret'),
                ...$data,
            ]);
        } catch (ConnectionException $exception) {
            throw new AccountsUnavailable(
                'Accounts no respondió.',
                previous: $exception,
            );
        }

        if ($response->serverError() || $response->tooManyRequests()) {
            throw new AccountsUnavailable(
                "Accounts respondió {$response->status()}.",
            );
        }

        return $response;
    }
}
