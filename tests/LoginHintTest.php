<?php

/**
 * Follow a sign-in start to the authorization request Accounts would receive.
 *
 * @return array<string, string>
 */
function authorizationQueryFor(string $path): array
{
    $response = test()->get($path)->assertRedirect();
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query;
}

test('the sign-in asks Accounts to show the account chooser and to prefer an account', function () {
    $query = authorizationQueryFor('/auth/accounts/redirect?prompt=select_account&login_hint=ana@example.com');

    expect($query)->toMatchArray(['prompt' => 'select_account', 'login_hint' => 'ana@example.com']);
});

test('the login address passes the account hint and the prompt on to Accounts', function () {
    $entry = test()->get('/login?prompt=select_account&login_hint=ana@example.com')->assertRedirect();
    parse_str((string) parse_url($entry->headers->get('Location'), PHP_URL_QUERY), $entryQuery);

    expect($entryQuery)->toMatchArray(['prompt' => 'select_account', 'login_hint' => 'ana@example.com']);

    $query = authorizationQueryFor($entry->headers->get('Location'));

    expect($query)->toMatchArray(['prompt' => 'select_account', 'login_hint' => 'ana@example.com']);
});

test('an account hint is accepted as an email or as an account identifier', function (string $hint) {
    expect(authorizationQueryFor('/auth/accounts/redirect?'.http_build_query(['login_hint' => $hint])))
        ->toMatchArray(['login_hint' => $hint]);
})->with([
    'email' => ['ana@example.com'],
    'account identifier' => ['0198f3a4-6b5e-7c1d-9a2b-3c4d5e6f7a8b'],
]);

test('an account hint that is not an email or an identifier, or is too long, is dropped without an error', function (string $hint) {
    expect(authorizationQueryFor('/auth/accounts/redirect?'.http_build_query(['login_hint' => $hint])))
        ->not->toHaveKey('login_hint');
})->with([
    'free text' => ['ana perez'],
    'too long' => [str_repeat('a', 250).'@example.com'],
    'markup' => ['<script>alert(1)</script>'],
]);

test('only the supported prompts are sent to Accounts', function (string $prompt, bool $sent) {
    $query = authorizationQueryFor('/auth/accounts/redirect?'.http_build_query(['prompt' => $prompt]));

    $sent ? expect($query)->toMatchArray(['prompt' => $prompt]) : expect($query)->not->toHaveKey('prompt');
})->with([
    'login' => ['login', true],
    'consent' => ['consent', true],
    'select account' => ['select_account', true],
    'none' => ['none', false],
    'unknown' => ['create', false],
]);

test('the authentication parameters keep working beside the new ones', function () {
    $query = authorizationQueryFor('/auth/accounts/redirect?acr_values=urn:accounts:acr:mfa&max_age=300&prompt=select_account');

    expect($query)->toMatchArray(['acr_values' => 'urn:accounts:acr:mfa', 'max_age' => '300', 'prompt' => 'select_account']);
});
