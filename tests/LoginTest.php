<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use LuisML\AccountsClient\Tests\Fixtures\User;

test('login sends the person to Accounts with state, nonce and S256 PKCE', function () {
    $login = $this->beginLogin();
    $query = $login['query'];

    expect($login['location'])->toStartWith('https://accounts.example.test/oauth/authorize?')
        ->and($query)->toMatchArray([
            'response_type' => 'code',
            'client_id' => 'client-id-1',
            'redirect_uri' => 'https://app.example.test/auth/accounts/callback',
            'scope' => 'openid profile email roles',
            'code_challenge_method' => 'S256',
        ])
        ->and($query['state'])->toHaveLength(40)
        ->and($query['nonce'])->toHaveLength(40)
        ->and(strlen($query['code_challenge']))->toBeGreaterThanOrEqual(43);
});

test('the callback logs the person in, linked by issuer and subject, and keeps tokens server-side and encrypted', function () {
    $this->login()->assertRedirect('/');

    $user = User::firstOrFail();

    expect($user->accounts_issuer)->toBe('https://accounts.example.test')
        ->and($user->accounts_sub)->toBe('subject-1')
        ->and($user->name)->toBe('Ana Pérez')
        ->and($user->password)->not->toBeEmpty();
    $this->assertAuthenticatedAs($user);

    $stored = session('accounts.session');
    expect($stored['access_token'])->not->toContain('access-initial')
        ->and(Crypt::decryptString($stored['access_token']))->toBe('access-initial')
        ->and(Crypt::decryptString($stored['refresh_token']))->toBe('refresh-initial');
});

test('the same subject reuses its user and a changed email never creates or merges identities', function () {
    $this->login();
    auth()->logout();
    $this->flushSession();
    $this->login();

    expect(User::count())->toBe(1);

    auth()->logout();
    $this->flushSession();
    $this->accounts->subject = 'subject-2';
    $this->login();

    expect(User::count())->toBe(2)->and(User::pluck('accounts_sub')->all())->toBe(['subject-1', 'subject-2']);
});

test('the session id changes at login', function () {
    $this->startSession();
    $before = session()->getId();

    $this->login();

    expect(session()->getId())->not->toBe($before);
});

test('the code is sent with the verifier and the exact redirect, never the client secret in the URL', function () {
    $login = $this->beginLogin();
    $this->get('/auth/accounts/callback?'.http_build_query(['code' => 'code-1', 'state' => $login['state']]));

    $request = collect(\Illuminate\Support\Facades\Http::recorded())->map(fn ($pair) => $pair[0])->first(fn ($request) => str_ends_with($request->url(), '/oauth/token'));

    expect($request->data())->toMatchArray([
        'grant_type' => 'authorization_code',
        'code' => 'code-1',
        'redirect_uri' => 'https://app.example.test/auth/accounts/callback',
        'client_id' => 'client-id-1',
    ])->and($request->data()['code_verifier'])->toHaveLength(64)
        ->and($request->url())->not->toContain('client-secret');
});

test('unknown, reused and expired state never log anyone in', function () {
    $this->get('/auth/accounts/callback?code=x&state=nope')->assertRedirect('/');
    $this->assertGuest();

    $login = $this->beginLogin();
    $this->get('/auth/accounts/callback?'.http_build_query(['code' => 'code-1', 'state' => $login['state']]));
    auth()->logout();
    $this->get('/auth/accounts/callback?'.http_build_query(['code' => 'code-1', 'state' => $login['state']]));
    $this->assertGuest();

    $late = $this->beginLogin();
    $this->travel(6)->minutes();
    $this->get('/auth/accounts/callback?'.http_build_query(['code' => 'code-1', 'state' => $late['state']]));
    $this->assertGuest();
    expect(session('accounts.error'))->not->toBeNull();
});

test('a denial consumes the transaction and grants nothing', function () {
    $login = $this->beginLogin();

    $this->get('/auth/accounts/callback?'.http_build_query(['error' => 'access_denied', 'state' => $login['state']]))->assertRedirect('/');

    expect(session('accounts.error'))->toContain('No se concedió');
    $this->get('/auth/accounts/callback?'.http_build_query(['code' => 'code-1', 'state' => $login['state']]));
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('several tabs keep independent transactions', function () {
    $a = $this->beginLogin();
    $b = $this->beginLogin();

    expect($a['state'])->not->toBe($b['state'])->and($a['nonce'])->not->toBe($b['nonce']);

    $this->accounts->lastNonce = ['nonce' => $a['nonce']];
    $this->get('/auth/accounts/callback?'.http_build_query(['code' => 'code-1', 'state' => $a['state']]))->assertRedirect('/');
    $this->assertAuthenticated();

    auth()->logout();
    $this->accounts->lastNonce = ['nonce' => $b['nonce']];
    $this->get('/auth/accounts/callback?'.http_build_query(['code' => 'code-1', 'state' => $b['state']]))->assertRedirect('/');
    $this->assertAuthenticated();
});

test('the intended destination survives, but only local paths', function () {
    $this->withSession(['url.intended' => 'https://evil.example/steal?x=1'])->get('/auth/accounts/redirect');
    $evil = array_key_first(session('accounts.transactions'));
    expect(session('accounts.transactions')[$evil]['intended'])->toBe('/steal?x=1');

    $this->withSession(['url.intended' => '//evil.example/x'])->get('/auth/accounts/redirect');
    expect(collect(session('accounts.transactions'))->pluck('intended')->filter()->all())->not->toContain('//evil.example/x');
});

test('login needs Accounts: when it is down the person gets a recoverable error', function () {
    $this->accounts->down = true;

    $this->get('/auth/accounts/redirect')->assertStatus(503)->assertSee('Reintentar');
    $this->assertGuest();
});

test('the token endpoint failing or lacking an ID token logs nobody in', function (Closure $response) {
    $login = $this->beginLogin();
    $this->accounts->tokenResponse = $response;

    $this->get('/auth/accounts/callback?'.http_build_query(['code' => 'code-1', 'state' => $login['state']]))->assertRedirect('/');

    $this->assertGuest();
    expect(User::count())->toBe(0);
})->with([
    'invalid_grant' => [fn () => \Illuminate\Support\Facades\Http::response(['error' => 'invalid_grant'], 400)],
    'no id token' => [fn () => \Illuminate\Support\Facades\Http::response(['access_token' => 'a', 'expires_in' => 300])],
    'malformed' => [fn () => \Illuminate\Support\Facades\Http::response('not json', 200)],
]);

test('invalid ID Tokens never log anyone in', function (Closure $make) {
    $login = $this->beginLogin();
    $nonce = $login['nonce'];
    $accounts = $this->accounts;
    $accounts->tokenResponse = fn () => \Illuminate\Support\Facades\Http::response([
        'expires_in' => 300, 'access_token' => 'a', 'refresh_token' => 'r', 'id_token' => $make($accounts, $nonce),
    ]);

    $this->get('/auth/accounts/callback?'.http_build_query(['code' => 'code-1', 'state' => $login['state']]))->assertRedirect('/');

    $this->assertGuest();
    expect(User::count())->toBe(0);
})->with([
    'wrong nonce' => [fn ($accounts) => $accounts->idToken('another-nonce')],
    'wrong audience' => [fn ($accounts, $nonce) => $accounts->idToken($nonce, ['aud' => 'someone-else'])],
    'wrong issuer' => [fn ($accounts, $nonce) => $accounts->idToken($nonce, ['iss' => 'https://evil.example.test'])],
    'issuer with extra path' => [fn ($accounts, $nonce) => $accounts->idToken($nonce, ['iss' => 'https://accounts.example.test/'])],
    'expired' => [fn ($accounts, $nonce) => $accounts->idToken($nonce, ['iat' => now()->subHour()->getTimestamp(), 'exp' => now()->subMinutes(10)->getTimestamp()])],
    'signed with another key' => [fn ($accounts, $nonce) => $accounts->forgedIdToken($nonce)],
    'symmetric algorithm' => [fn ($accounts, $nonce) => $accounts->idToken($nonce, [], 'HS256')],
    'unknown kid' => [fn ($accounts, $nonce) => $accounts->idToken($nonce, ['kid' => 'nobody'])],
    'garbage' => [fn () => 'a.b.c'],
]);

test('an unknown kid refreshes the keys once and then gives up', function () {
    $login = $this->beginLogin();
    $nonce = $login['nonce'];
    $accounts = $this->accounts;
    $accounts->tokenResponse = fn () => \Illuminate\Support\Facades\Http::response([
        'expires_in' => 300, 'access_token' => 'a', 'id_token' => $accounts->idToken($nonce, ['kid' => 'nobody']),
    ]);
    $this->get('/auth/accounts/callback?'.http_build_query(['code' => 'code-1', 'state' => $login['state']]));

    expect($accounts->called('GET /oauth/jwks'))->toBe(2);

    $again = $this->beginLogin();
    $nonce = $again['nonce'];
    $this->get('/auth/accounts/callback?'.http_build_query(['code' => 'code-1', 'state' => $again['state']]));

    expect($accounts->called('GET /oauth/jwks'))->toBe(2);
});

test('UserInfo for a different subject is refused', function () {
    $login = $this->beginLogin();
    $accounts = $this->accounts;
    $nonce = $login['nonce'];
    $accounts->tokenResponse = fn () => \Illuminate\Support\Facades\Http::response([
        'expires_in' => 300, 'access_token' => 'a', 'id_token' => $accounts->idToken($nonce, ['sub' => 'someone-else']),
    ]);

    $this->get('/auth/accounts/callback?'.http_build_query(['code' => 'code-1', 'state' => $login['state']]));

    $this->assertGuest();
});

test('a discovery document that declares another issuer is rejected', function () {
    config(['accounts.issuer' => 'https://other.example.test']);
    \Illuminate\Support\Facades\Cache::flush();

    $this->get('/auth/accounts/redirect')->assertStatus(503);
});

test('an email Accounts verified arrives verified, because Accounts is the authority on that', function () {
    $this->login();

    expect(User::firstOrFail()->email_verified_at)->not->toBeNull();
});

test('a rejected sign-in logs why, so the failing step can be told apart', function () {
    Log::spy();
    $this->accounts->userInfoFails = true;
    $login = $this->beginLogin();
    $this->accounts->lastNonce = ['nonce' => $login['nonce']];

    $this->get('/auth/accounts/callback?'.http_build_query(['code' => 'code-1', 'state' => $login['state']]))->assertRedirect('/');

    $this->assertGuest();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Acceso con LuisML rechazado.'
        && $context['reason'] === RuntimeException::class
        && str_contains($context['message'], 'UserInfo'))->once();
});
