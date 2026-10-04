<?php

namespace LuisML\AccountsClient\Actions;

use LuisML\AccountsClient\AccountsSession;
use LuisML\AccountsClient\Identity;

class SyncProfile
{
    public function __construct(private Discovery $discovery, private AccountsHttp $http) {}

    /**
     * Read the profile Accounts holds and let the app's user resolver update its local copy. The
     * subject must be the one this session belongs to. Returns false when it could not be refreshed.
     */
    public function handle(AccountsSession $state): bool
    {
        $token = $state->accessToken();
        $subject = $state->subject();

        if ($token === null || $subject === null) {
            return false;
        }

        $info = $this->http->client()->withToken($token)->get($this->discovery->metadata()['userinfo_endpoint']);

        if (! $info->successful() || $info->json('sub') !== $subject) {
            return false;
        }

        app((string) config('accounts.user_resolver'))(new Identity(
            issuer: (string) config('accounts.issuer'),
            subject: $subject,
            name: $info->json('name'),
            email: $info->json('email'),
            emailVerified: (bool) $info->json('email_verified', false),
        ));

        return true;
    }
}
