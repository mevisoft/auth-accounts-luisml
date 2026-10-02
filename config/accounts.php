<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Accounts (issuer)
    |--------------------------------------------------------------------------
    |
    | Exact, fixed issuer URL of Accounts LuisML. It is compared literally with the
    | `iss` of every ID Token and with the discovery document; it is never derived
    | from the request. Credentials live only in the backend configuration.
    |
    */

    'issuer' => env('ACCOUNTS_ISSUER'),

    'client_id' => env('ACCOUNTS_CLIENT_ID'),

    'client_secret' => env('ACCOUNTS_CLIENT_SECRET'),

    /*
    | Callback URL, registered exactly in Accounts. Must be HTTPS.
    */

    'redirect' => env('ACCOUNTS_REDIRECT_URI'),

    /*
    | Where a person lands after a successful login when no other destination applies.
    */

    'home' => env('ACCOUNTS_HOME', '/'),

    'scopes' => ['openid', 'profile', 'email'],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    'http' => [
        'connect_timeout' => (int) env('ACCOUNTS_CONNECT_TIMEOUT', 2),
        'timeout' => (int) env('ACCOUNTS_TIMEOUT', 3),
    ],

    // How long a successful validation of the access may serve protected operations.
    'validation_seconds' => 60,

    // At most one activity report per session within this interval.
    'activity_interval_seconds' => 15,

    // Tolerance for JWT clock differences; it never extends the 60-second bound.
    'clock_skew_seconds' => 30,

    'transaction_minutes' => 5,

    // How long a request waits for another one that is refreshing the same session's tokens.
    'refresh_lock_wait_seconds' => 5,

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    |
    | Identities are linked by (issuer, sub), never by email. The default resolver finds or
    | creates a row of `user_model` by those two columns; replace it with any invokable class
    | taking an Identity and returning an Authenticatable (or null to refuse).
    |
    */

    'users_table' => 'users',

    'user_model' => 'App\\Models\\User',

    'user_resolver' => LuisML\AccountsClient\Actions\ResolveModelUser::class,

    'routes' => [
        'prefix' => 'auth/accounts',
    ],

];
