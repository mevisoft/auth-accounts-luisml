<?php

use Illuminate\Support\Facades\Crypt;
use LuisML\AccountsClient\Tests\Fixtures\User;

function loginWith(array $query = []): array
{
    $response = test()->get('/auth/accounts/redirect?'.http_build_query($query))->assertRedirect();
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $authorize);
    test()->accounts->lastNonce = ['nonce' => $authorize['nonce']];

    return $authorize;
}

function completeLoginFor(array $authorize)
{
    return test()->get('/auth/accounts/callback?'.http_build_query(['code' => 'code-1', 'state' => $authorize['state']]));
}

test('only the authentication parameters the client understands reach Accounts', function () {
    $authorize = loginWith([
        'acr_values' => 'urn:accounts:acr:phr',
        'max_age' => '300',
        'prompt' => 'login',
        'login_hint' => 'ana@example.test',
        'scope' => 'admin',
    ]);

    expect($authorize)->toMatchArray(['acr_values' => 'urn:accounts:acr:phr', 'max_age' => '300', 'prompt' => 'login', 'scope' => 'openid profile email roles'])
        ->not->toHaveKey('login_hint');

    $invalid = loginWith(['acr_values' => 'bad value; drop', 'max_age' => '-1', 'prompt' => 'none']);

    expect($invalid)->not->toHaveKeys(['acr_values', 'max_age', 'prompt']);
});

test('how the person signed in is kept with the session, and the ID Token is stored encrypted', function () {
    $this->accounts->idTokenClaims = ['auth_time' => now()->getTimestamp(), 'acr' => 'urn:accounts:acr:mfa', 'amr' => ['pwd', 'mfa'], 'sid' => 'sid-1'];

    completeLoginFor(loginWith())->assertRedirect('/');

    $stored = session('accounts.session');
    expect($stored['acr'])->toBe('urn:accounts:acr:mfa')
        ->and($stored['amr'])->toBe(['pwd', 'mfa'])
        ->and($stored['sid'])->toBe('sid-1')
        ->and($stored['auth_time'])->toBe(now()->getTimestamp())
        ->and(Crypt::decryptString($stored['id_token']))->toContain('.')
        ->and($stored['id_token'])->not->toBe(Crypt::decryptString($stored['id_token']));
});

test('a requested max_age is only satisfied by an ID Token that proves a recent authentication', function () {
    completeLoginFor(loginWith(['max_age' => '60']));
    $this->assertGuest();

    $this->accounts->idTokenClaims = ['auth_time' => now()->subHour()->getTimestamp()];
    completeLoginFor(loginWith(['max_age' => '60']));
    $this->assertGuest();

    $this->accounts->idTokenClaims = ['auth_time' => now()->subSeconds(10)->getTimestamp()];
    completeLoginFor(loginWith(['max_age' => '60']));
    $this->assertAuthenticated();
    expect(User::count())->toBe(1);
});

function stepUpRoute(string $requirements): void
{
    app('router')->middleware(['web', 'accounts.access', "accounts.step-up:{$requirements}"])->get('/sensitive', fn () => 'sensitive');
}

test('step-up lets a session through when it already meets the level and the age', function () {
    $this->accounts->idTokenClaims = ['auth_time' => now()->getTimestamp(), 'acr' => 'urn:accounts:acr:phr'];
    completeLoginFor(loginWith());
    stepUpRoute('acr=urn:accounts:acr:mfa,max_age=300');

    $this->get('/sensitive')->assertOk()->assertSee('sensitive');
});

test('step-up sends a weaker session to Accounts to raise it, once, and then refuses instead of looping', function () {
    $this->accounts->idTokenClaims = ['auth_time' => now()->getTimestamp(), 'acr' => 'urn:accounts:acr:pwd'];
    completeLoginFor(loginWith());
    stepUpRoute('acr=urn:accounts:acr:phr');

    $this->get('/sensitive')->assertRedirect(route('accounts.login', ['acr_values' => 'urn:accounts:acr:phr']));
    expect(session('url.intended'))->toEndWith('/sensitive');

    $this->get('/sensitive')->assertForbidden();
});

test('step-up asks for a fresher authentication when the last one is older than max_age', function () {
    $this->accounts->idTokenClaims = ['auth_time' => now()->subHour()->getTimestamp(), 'acr' => 'urn:accounts:acr:mfa'];
    completeLoginFor(loginWith());
    stepUpRoute('max_age=300');

    $this->get('/sensitive')->assertRedirect(route('accounts.login', ['max_age' => 300]));
});

test('step-up answers API clients with a code they can act on', function () {
    $this->accounts->idTokenClaims = ['auth_time' => now()->getTimestamp(), 'acr' => 'urn:accounts:acr:pwd'];
    completeLoginFor(loginWith());
    stepUpRoute('acr=urn:accounts:acr:mfa');

    $this->getJson('/sensitive')->assertUnauthorized()->assertJson(['code' => 'accounts_step_up_required', 'acr' => 'urn:accounts:acr:mfa']);
});
