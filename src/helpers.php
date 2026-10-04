<?php

use LuisML\AccountsClient\Accounts;

if (! function_exists('accounts_account_url')) {
    /**
     * The address of the person's central account page in Accounts.
     */
    function accounts_account_url(): string
    {
        return Accounts::accountUrl();
    }
}
