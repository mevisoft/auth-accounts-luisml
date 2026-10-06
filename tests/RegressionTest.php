<?php

use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use LuisML\AccountsClient\AccountsSession;
use LuisML\AccountsClient\Actions\RefreshAccountsSession;
use LuisML\AccountsClient\Actions\ResolveModelUser;
use LuisML\AccountsClient\Actions\SyncProfile;
use LuisML\AccountsClient\Actions\ValidateIdentityToken;
use LuisML\AccountsClient\Identity;
use LuisML\AccountsClient\Tests\Fixtures\User;

test('ID tokens require expiration, issue time and a nonempty subject', function (array $claims) {
    $jwt = $this->accounts->idToken('nonce', $claims);

    expect(fn () => app(ValidateIdentityToken::class)->handle($jwt, 'nonce'))->toThrow(RuntimeException::class);
})->with([
    'missing exp' => [['exp' => null]],
    'missing iat' => [['iat' => null]],
    'empty sub' => [['sub' => '']],
    'future auth_time' => [fn () => ['auth_time' => now()->addHour()->getTimestamp()]],
]);

test('logout tokens may identify a session without a subject', function () {
    $this->post('/auth/accounts/backchannel-logout', [
        'logout_token' => $this->accounts->logoutToken('sid-1', ['sub' => null]),
    ])->assertOk();
});

test('logout tokens require nonempty replay and session identifiers', function (array $claims) {
    $this->post('/auth/accounts/backchannel-logout', [
        'logout_token' => $this->accounts->logoutToken('sid-1', $claims),
    ])->assertStatus(400);
})->with([
    'empty jti' => [['jti' => '']],
    'empty sid' => [['sid' => '']],
    'missing iat' => [['iat' => null]],
    'missing exp' => [['exp' => null]],
]);

test('malformed callback query parameters are rejected without a server error', function (array $query) {
    $login = $this->beginLogin();
    $this->get('/auth/accounts/callback?'.http_build_query([
        'state' => $login['state'], 'code' => 'code-1', ...$query,
    ]))->assertRedirect('/');
    $this->assertGuest();
})->with([
    'array state' => [['state' => ['invalid']]],
    'array code' => [['code' => ['invalid']]],
]);

test('invalid discovery endpoints produce a recoverable response', function ($endpoint) {
    $this->accounts->metadataOverrides = ['authorization_endpoint' => $endpoint];
    $this->get('/auth/accounts/redirect')->assertStatus(503);
})->with([null, '', ['bad'], 'javascript:alert(1)']);

test('issuer matching is literal even when configured with a trailing slash', function () {
    config(['accounts.issuer' => 'https://accounts.example.test/']);
    $this->get('/auth/accounts/redirect')->assertStatus(503);
});

test('a matching issuer ending with a slash can sign in', function () {
    config(['accounts.issuer' => 'https://accounts.example.test/']);
    $this->accounts->metadataOverrides = ['issuer' => 'https://accounts.example.test/'];
    $this->accounts->idTokenClaims = ['iss' => 'https://accounts.example.test/'];
    $this->login()->assertRedirect('/');
    $this->assertAuthenticated();
});

test('email verification follows the current email and authority', function (string $email, bool $verified) {
    $this->login();
    $resolver = app(ResolveModelUser::class);
    $user = $resolver(new Identity(config('accounts.issuer'), 'subject-1', 'Ana', $email, $verified));

    expect($user->email)->toBe($email)->and($user->email_verified_at)->toBeNull();
})->with([
    'changed unverified email' => ['new@example.test', false],
    'verification withdrawn' => ['ana@example.test', false],
]);

test('the string false cannot verify an email or a scalar grant a role', function () {
    $this->accounts->profileOverrides = ['email_verified' => 'false', 'roles' => 'admin'];
    $this->login();
    $user = User::firstOrFail();
    expect($user->email_verified_at)->toBeNull()->and($user->accountsRoles())->toBe([]);
});

test('profile synchronization updates the user used for authorization in the same request', function () {
    $this->accounts->roles = ['admin'];
    $this->login();
    $this->accounts->roles = [];
    $this->app['router']->middleware(['web', 'accounts.access'])->get('/role-check', fn () => response()->json([
        'admin' => Auth::user()->hasAccountsRole('admin'),
    ]));
    $this->travel(61)->seconds();

    $this->getJson('/role-check')->assertOk()->assertJson(['admin' => false]);
});

test('a rejected profile sync is not marked as successful', function () {
    $this->login();
    $this->app->bind('reject-profile', fn () => fn (Identity $identity) => null);
    config(['accounts.user_resolver' => 'reject-profile']);

    expect(app(SyncProfile::class)->handle(AccountsSession::for(app('session.store'))))->toBeFalse();
});

test('invalid introspection answers block without destroying the session', function (mixed $body, int $status) {
    $this->login();
    $this->accounts->introspectionResponse = fn () => Http::response($body, $status);
    $this->travel(61)->seconds();

    $this->postJson('/protected')->assertStatus(503);
    $this->assertAuthenticated();
})->with([
    'string false' => [['active' => 'false'], 200],
    'missing active' => [[], 200],
    'invalid JSON' => ['not json', 200],
    'wrong expiry type' => [['active' => true, 'accounts_session_expires_at' => []], 200],
    'forbidden endpoint' => [['active' => true], 403],
]);

test('introspection for another subject or an ended session cannot authorize a mutation', function (array $body) {
    $this->login();
    $this->accounts->introspectionResponse = fn () => Http::response($body);
    $this->travel(61)->seconds();

    $this->postJson('/protected')->assertUnauthorized();
    $this->assertGuest();
})->with([
    'different subject' => [['active' => true, 'sub' => 'another-person']],
    'expired central session' => [['active' => true, 'accounts_session_expires_at' => 1]],
]);

test('queue failures cannot prevent local logout', function () {
    $this->login();
    $this->accounts->revokeFails = true;
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));

    $this->post('/auth/accounts/logout')->assertRedirect('/');
    $this->assertGuest();
    expect(session('accounts.session'))->toBeNull();
});

test('a synchronous revocation retry failing cannot prevent local logout', function () {
    $this->login();
    $this->accounts->revokeFails = true;
    $this->post('/auth/accounts/logout')->assertRedirect('/');
    $this->assertGuest();
});

test('stale independent session snapshots share a refresh result without redeeming twice', function () {
    $this->login();
    $this->travel(301)->seconds();
    $first = app('session.store');
    $second = new Store('parallel', new ArraySessionHandler(120), $first->getId());
    $second->replace($first->all());
    $refresh = app(RefreshAccountsSession::class);
    $calls = $this->accounts->called('POST /oauth/token');

    expect($refresh->handle($first))->toBeTrue();
    $expires = $first->get('accounts.session.access_expires_at');
    $this->travel(2)->seconds();
    expect($refresh->handle($second))->toBeTrue()
        ->and($this->accounts->called('POST /oauth/token'))->toBe($calls + 1)
        ->and(AccountsSession::for($second)->accessToken())->toBe(AccountsSession::for($first)->accessToken())
        ->and($second->get('accounts.session.access_expires_at'))->toBe($expires);
});

test('an ambiguous refresh is not retried by a stale session snapshot', function () {
    $this->login();
    $this->travel(301)->seconds();
    $first = app('session.store');
    $second = new Store('parallel', new ArraySessionHandler(120), $first->getId());
    $second->replace($first->all());
    $this->accounts->refreshTimesOut = true;
    $refresh = app(RefreshAccountsSession::class);
    $calls = $this->accounts->called('POST /oauth/token');

    expect($refresh->handle($first))->toBeFalse();
    $this->accounts->refreshTimesOut = false;
    expect($refresh->handle($second))->toBeFalse()
        ->and($this->accounts->called('POST /oauth/token'))->toBe($calls + 1);
});

test('a refresh response without a replacement refresh token preserves the original', function () {
    $this->login();
    $this->travel(301)->seconds();
    $this->accounts->refreshResponse = fn () => Http::response(['access_token' => 'next-access', 'expires_in' => 300]);

    expect(app(RefreshAccountsSession::class)->handle(app('session.store')))->toBeTrue()
        ->and(AccountsSession::for(app('session.store'))->refreshToken())->toBe('refresh-initial');
});

test('a timed out code exchange is reported as unavailable without authenticating', function () {
    $login = $this->beginLogin();
    $this->accounts->tokenResponse = fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout');
    $this->get('/auth/accounts/callback?'.http_build_query(['state' => $login['state'], 'code' => 'code-1']))
        ->assertStatus(503);
    $this->assertGuest();
});

test('invalid token responses cannot partially authenticate or create a user', function (array $overrides) {
    $this->accounts->tokenResponse = fn () => Http::response([
        'access_token' => 'access', 'expires_in' => 300,
        'id_token' => $this->accounts->idToken($this->accounts->lastNonce['nonce']),
        ...$overrides,
    ]);

    $this->login()->assertRedirect('/');
    $this->assertGuest();
    expect(User::count())->toBe(0)->and(session('accounts.session'))->toBeNull();
})->with([
    'empty access token' => [['access_token' => '']],
    'array refresh token' => [['refresh_token' => ['bad']]],
    'missing lifetime' => [['expires_in' => null]],
    'negative lifetime' => [['expires_in' => -1]],
    'unsupported token type' => [['token_type' => 'MAC']],
]);

test('invalid refresh payloads drop the session without a type error', function () {
    $this->login();
    $this->travel(301)->seconds();
    $this->accounts->refreshResponse = fn () => Http::response(['access_token' => 'access', 'expires_in' => 300, 'refresh_token' => []]);

    $this->getJson('/protected')->assertUnauthorized();
    $this->assertGuest();
});

test('slow introspection cannot authorize a mutation after the validation horizon', function () {
    $this->login();
    $this->travel(61)->seconds();
    $this->accounts->introspectionResponse = function () {
        $this->travel(61)->seconds();

        return Http::response(['active' => true]);
    };

    $this->postJson('/protected')->assertStatus(503);
    $this->assertAuthenticated();
});

test('invalid signing keys are rejected predictably', function (array $overrides) {
    expect(fn () => \LuisML\AccountsClient\Support\JwkToPem::convert([
        'kty' => 'RSA', 'n' => 'AQ', 'e' => 'AQAB', ...$overrides,
    ]))->toThrow(RuntimeException::class);
})->with([
    'wrong key type' => [['kty' => 'EC']],
    'encryption key' => [['use' => 'enc']],
    'wrong algorithm' => [['alg' => 'RS512']],
    'missing modulus' => [['n' => null]],
    'empty modulus' => [['n' => '']],
    'invalid base64' => [['n' => '!!!']],
    'wrong key operations' => [['key_ops' => ['encrypt']]],
]);

test('logout replay protection lasts until the signed token expires', function () {
    $jwt = $this->accounts->logoutToken('sid', ['exp' => now()->addHours(2)->getTimestamp()]);
    $this->post('/auth/accounts/backchannel-logout', ['logout_token' => $jwt])->assertOk();
    $this->travel(61)->minutes();
    $this->post('/auth/accounts/backchannel-logout', ['logout_token' => $jwt])->assertStatus(400);
});

test('a cached refresh result cannot extend the access token past its original expiration', function () {
    $this->login();
    $this->travel(301)->seconds();
    $first = app('session.store');
    $second = new Store('parallel', new ArraySessionHandler(120), $first->getId());
    $second->replace($first->all());
    $refresh = app(RefreshAccountsSession::class);
    $calls = $this->accounts->called('POST /oauth/token');

    expect($refresh->handle($first))->toBeTrue();
    $this->travel(301)->seconds();
    expect($refresh->handle($second))->toBeFalse()
        ->and($this->accounts->called('POST /oauth/token'))->toBe($calls + 1);
});

test('continuous activity never postpones introspection and role revocations indefinitely', function () {
    $this->accounts->roles = ['admin'];
    $this->login();
    $this->accounts->roles = [];

    for ($i = 0; $i < 4; $i++) {
        $this->travel(16)->seconds();
        $this->get('/protected')->assertOk();
    }

    expect($this->accounts->called('POST /oauth/introspect'))->toBe(1)
        ->and(User::firstOrFail()->accountsRoles())->toBe([]);
});

test('discovery endpoint query parameters are preserved during login', function () {
    $this->accounts->metadataOverrides = ['authorization_endpoint' => 'https://accounts.example.test/oauth/authorize?tenant=app'];
    $login = $this->beginLogin();

    expect($login['query'])->toMatchArray(['tenant' => 'app', 'response_type' => 'code']);
});

test('an intended path containing backslashes cannot redirect to another host', function () {
    $this->withSession(['url.intended' => '/\\evil.example/steal']);
    $this->login()->assertRedirect('/');
});
