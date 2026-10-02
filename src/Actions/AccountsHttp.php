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
            ->acceptJson();
    }

    /**
     * A POST with the client's credentials (client_secret_post). Network failures and 5xx are
     * "unavailable": they say nothing about the access and are never retried automatically.
     *
     * @param  array<string, mixed>  $data
     */
    public function post(string $path, array $data, ?string $bearer = null): Response
    {
        $request = $this->client()->asForm();

        if ($bearer !== null) {
            $request = $request->withToken($bearer);
        }

        try {
            $response = $request->post(rtrim((string) config('accounts.issuer'), '/').$path, [
                'client_id' => config('accounts.client_id'),
                'client_secret' => config('accounts.client_secret'),
                ...$data,
            ]);
        } catch (ConnectionException $exception) {
            throw new AccountsUnavailable('Accounts no respondió.', previous: $exception);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new AccountsUnavailable("Accounts respondió {$response->status()}.");
        }

        return $response;
    }
}
