<?php

use Illuminate\Support\Facades\Auth;

function signedInWithSession(string $sid = 'sid-1'): void
{
    test()->accounts->idTokenClaims = ['sid' => $sid];
    test()->login()->assertRedirect('/');
}

test('a valid logout token ends the local session of that Accounts session on its next request', function () {
    signedInWithSession('sid-1');
    $this->get('/protected')->assertOk();

    $this->postJson('/auth/accounts/backchannel-logout', ['logout_token' => $this->accounts->logoutToken('sid-1')])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $this->get('/protected')->assertRedirect(route('accounts.login'));
    $this->assertGuest();
});

test('a logout token for another session leaves this one alone', function () {
    signedInWithSession('sid-1');

    $this->postJson('/auth/accounts/backchannel-logout', ['logout_token' => $this->accounts->logoutToken('sid-2')])->assertOk();

    $this->get('/protected')->assertOk();
});

test('a logout token is accepted once', function () {
    $token = $this->accounts->logoutToken('sid-1', ['jti' => 'same']);

    $this->postJson('/auth/accounts/backchannel-logout', ['logout_token' => $token])->assertOk();
    $this->postJson('/auth/accounts/backchannel-logout', ['logout_token' => $token])->assertStatus(400)->assertJson(['error' => 'invalid_request']);
});

test('tokens that are not valid logout tokens are refused and end nothing', function (callable $token) {
    signedInWithSession('sid-1');

    $this->postJson('/auth/accounts/backchannel-logout', ['logout_token' => $token($this->accounts)])->assertStatus(400);

    $this->get('/protected')->assertOk();
})->with([
    'forged signature' => [fn ($accounts) => $accounts->forgedIdToken('x')],
    'with a nonce' => [fn ($accounts) => $accounts->logoutToken('sid-1', ['nonce' => 'n'])],
    'without the logout event' => [fn ($accounts) => $accounts->logoutToken('sid-1', ['events' => ['other' => new stdClass]])],
    'without a session id' => [fn ($accounts) => $accounts->logoutToken('sid-1', ['sid' => null])],
    'for another audience' => [fn ($accounts) => $accounts->logoutToken('sid-1', ['aud' => 'other-client'])],
    'garbage' => [fn () => 'not-a-token'],
]);

test('the endpoint needs no cookies or CSRF token', function () {
    $this->post('/auth/accounts/backchannel-logout', ['logout_token' => $this->accounts->logoutToken('sid-1')])->assertOk();
});

test('signing out sends the browser to end the central session only when global logout is on', function () {
    signedInWithSession('sid-1');
    $this->post('/auth/accounts/logout')->assertRedirect('/');

    config(['accounts.global_logout' => true, 'accounts.post_logout_redirect' => 'https://app.example.test/bye']);
    signedInWithSession('sid-1');

    $response = $this->post('/auth/accounts/logout');
    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://accounts.example.test/oauth/end-session?')
        ->and($query['post_logout_redirect_uri'])->toBe('https://app.example.test/bye')
        ->and($query['id_token_hint'])->toContain('eyJ')
        ->and($query['state'])->not->toBeEmpty();
    $this->assertGuest();
    expect(Auth::check())->toBeFalse();
});

test('an Inertia sign-out is told to make a full visit to the central logout, not a cross-origin redirect', function () {
    config(['accounts.global_logout' => true, 'accounts.post_logout_redirect' => 'https://app.example.test/bye']);
    signedInWithSession('sid-1');

    $response = $this->withHeader('X-Inertia', 'true')->post('/auth/accounts/logout');

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toStartWith('https://accounts.example.test/oauth/end-session?')
        ->and($response->headers->get('Location'))->toBeNull();
});
