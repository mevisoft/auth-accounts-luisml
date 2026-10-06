<?php

namespace LuisML\AccountsClient\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
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

        $info = $this->http->get($this->discovery->metadata()['userinfo_endpoint'], $token);

        if (! $info->successful() || $info->json('sub') !== $subject) {
            return false;
        }

        $user = app((string) config('accounts.user_resolver'))(Identity::fromUserInfo(
            (string) config('accounts.issuer'), $subject, $info->json(),
        ));

        if (! $user instanceof Authenticatable) {
            return false;
        }

        // The guard already loaded a different model instance earlier in this request.
        // Replace it so downstream policies see role revocations immediately.
        if (Auth::check()) {
            if ((string) Auth::id() !== (string) $user->getAuthIdentifier()) {
                return false;
            }

            Auth::setUser($user);
        }

        return true;
    }
}
