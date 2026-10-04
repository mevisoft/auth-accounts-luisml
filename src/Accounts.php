<?php

namespace LuisML\AccountsClient;

final class Accounts
{
    /**
     * Where a person manages their central account (name, email, password, second factor): Accounts'
     * account page unless `accounts.account_url` overrides it.
     */
    public static function accountUrl(): string
    {
        $configured = config('accounts.account_url');

        return is_string($configured) && $configured !== ''
            ? $configured
            : rtrim((string) config('accounts.issuer'), '/').'/account';
    }
}
