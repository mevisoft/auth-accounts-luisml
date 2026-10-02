<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use LuisML\AccountsClient\Jobs\RevokeAccountsAccess;
use LuisML\AccountsClient\Tests\Fixtures\User;

beforeEach(function () {
    $this->login();
    $this->accounts->calls = [];
});

test('a protected page right after login is served without asking Accounts again', function () {
    $this->get('/protected')->assertOk();

    expect($this->accounts->called('POST /oauth/introspect'))->toBe(0);
});

test('once the window is over Accounts confirms the access again and the new window starts at the query', function () {
    $this->travel(61)->seconds();

    $this->get('/protected')->assertOk();
    $this->get('/protected')->assertOk();

    expect($this->accounts->called('POST /oauth/introspect'))->toBe(1);
});

test('the window never outlasts the access token or the central session', function () {
    $this->accounts->sessionExpiresIn = 20;
    $this->travel(61)->seconds();
    $this->get('/protected')->assertOk();

    $this->travel(21)->seconds();
    $this->get('/protected')->assertOk();

    expect($this->accounts->called('POST /oauth/introspect'))->toBe(2);
});

test('an inactive answer ends the local session at once', function () {
    $this->travel(61)->seconds();
    $this->accounts->introspectionActive = false;

    $this->get('/protected')->assertRedirect(route('accounts.login'));

    $this->assertGuest();
    $this->accounts->introspectionActive = true;
    $this->get('/protected')->assertRedirect(route('accounts.login'));
});

test('an inactive answer to a JSON client is a 401 the client can act on', function () {
    $this->travel(61)->seconds();
    $this->accounts->introspectionActive = false;

    $this->getJson('/protected')->assertUnauthorized()->assertJson(['code' => 'accounts_session_ended']);
});

test('when Accounts is down the window still serves the person, then blocks without losing the session', function () {
    $this->accounts->down = true;

    $this->get('/protected')->assertOk();

    $this->travel(61)->seconds();
    $this->get('/protected')->assertStatus(503)->assertSee('Reintentar')->assertHeader('Retry-After', '10');
    $this->assertAuthenticated();
});

test('a blocked change is never repeated by itself and says it was not applied', function () {
    $this->accounts->down = true;
    $this->travel(61)->seconds();

    $this->post('/protected')->assertStatus(503)->assertSee('Tu cambio no se aplicó')->assertDontSee('Reintentar');

    $this->accounts->down = false;
    expect($this->accounts->called('POST /protected'))->toBe(0);
    $this->post('/protected')->assertOk();
});

test('a slow answer does not widen the window: it counts from when the query started', function () {
    $passive = ['X-Accounts-Passive' => '1'];
    $this->travel(61)->seconds();
    $this->withHeaders($passive)->get('/protected')->assertOk();

    $this->travel(59)->seconds();
    $this->withHeaders($passive)->get('/protected')->assertOk();
    expect($this->accounts->called('POST /oauth/introspect'))->toBe(1);

    $this->travel(2)->seconds();
    $this->withHeaders($passive)->get('/protected')->assertOk();
    expect($this->accounts->called('POST /oauth/introspect'))->toBe(2);
});

test('a malfunctioning client configuration is treated as unavailable, not as a revocation', function () {
    $this->travel(61)->seconds();
    $this->accounts->introspectionStatus = 401;

    $this->get('/protected')->assertStatus(503);
    $this->assertAuthenticated();
});

test('guests are sent to log in, JSON clients get 401', function () {
    auth()->logout();
    $this->flushSession();

    $this->get('/protected')->assertRedirect(route('accounts.login'));
    $this->getJson('/protected')->assertUnauthorized();
});

test('an expired access token is refreshed once and the new tokens replace the old', function () {
    $this->travel(301)->seconds();

    $this->get('/protected')->assertOk();

    expect($this->accounts->called('POST /oauth/token'))->toBe(1);
    $stored = session('accounts.session');
    expect(Illuminate\Support\Facades\Crypt::decryptString($stored['access_token']))->not->toBe('access-initial');
});

test('concurrent requests never redeem the same refresh token twice', function () {
    config(['accounts.refresh_lock_wait_seconds' => 0]);
    $this->travel(301)->seconds();

    $store = app('session.store');
    $store->setId(str_repeat('a', 40));
    $holder = Cache::lock('accounts-client:refresh:'.$store->getId(), 10);
    $holder->get();

    expect(app(LuisML\AccountsClient\Actions\RefreshAccountsSession::class)->handle($store))->toBeFalse()
        ->and($this->accounts->called('POST /oauth/token'))->toBe(0);

    $holder->release();
    expect(app(LuisML\AccountsClient\Actions\RefreshAccountsSession::class)->handle($store))->toBeTrue()
        ->and($this->accounts->called('POST /oauth/token'))->toBe(1)
        ->and(app(LuisML\AccountsClient\Actions\RefreshAccountsSession::class)->handle($store))->toBeTrue()
        ->and($this->accounts->called('POST /oauth/token'))->toBe(1);
});

test('a refused refresh requires a new authorization', function () {
    $this->travel(301)->seconds();
    $this->accounts->refreshFails = true;

    $this->get('/protected')->assertRedirect(route('accounts.login'));

    $this->assertGuest();
});

test('an ambiguous refresh outcome drops the tokens: a possibly consumed token is never reused', function () {
    $this->travel(301)->seconds();
    $this->accounts->refreshTimesOut = true;

    $this->get('/protected')->assertRedirect(route('accounts.login'));
    $this->assertGuest();

    $this->accounts->refreshTimesOut = false;
    expect($this->accounts->called('POST /oauth/token'))->toBe(1);
});

test('person-initiated requests are reported, grouped every 15 seconds', function () {
    $this->travel(16)->seconds();
    $this->get('/protected')->assertOk();
    $this->get('/protected')->assertOk();

    expect($this->accounts->called('POST /api/v1/session-activity'))->toBe(1);

    $this->travel(16)->seconds();
    $this->post('/protected')->assertOk();

    expect($this->accounts->called('POST /api/v1/session-activity'))->toBe(2);
});

test('polling, prefetching and passive requests are never reported', function (array $headers) {
    $this->travel(16)->seconds();

    $this->withHeaders($headers)->get('/polling')->assertOk();

    expect($this->accounts->called('POST /api/v1/session-activity'))->toBe(0);
})->with([
    'passive flag' => [['X-Accounts-Passive' => '1']],
    'prefetch' => [['Purpose' => 'prefetch']],
    'inertia partial' => [['X-Inertia-Partial-Component' => 'x']],
]);

test('failed requests are not activity', function () {
    $this->travel(16)->seconds();
    $this->app['router']->middleware(['web', 'accounts.access', 'accounts.activity'])->get('/broken', fn () => abort(500));

    $this->get('/broken');

    expect($this->accounts->called('POST /api/v1/session-activity'))->toBe(0);
});

test('a failed report neither breaks the request nor extends anything locally', function () {
    $this->accounts->activityFails = true;
    $this->travel(16)->seconds();

    $this->get('/protected')->assertOk();

    expect(session('accounts.session')['session_expires_at'])->toBeNull();
});

test('a report near the end of the central session goes out without waiting for the interval', function () {
    $this->accounts->sessionExpiresIn = 40;
    $this->travel(16)->seconds();
    $this->get('/protected');
    expect($this->accounts->called('POST /api/v1/session-activity'))->toBe(1);

    $this->travel(3)->seconds();
    $this->get('/protected');

    expect($this->accounts->called('POST /api/v1/session-activity'))->toBe(2);
});

test('logging out revokes this browser access and destroys the local session', function () {
    $this->post(route('accounts.logout'))->assertRedirect('/')->assertSessionHas('status', 'Cerraste sesión.');

    $this->assertGuest();
    expect($this->accounts->called('POST /oauth/revoke'))->toBe(1);
});

test('if the revocation cannot be confirmed, a durable job retries it and the person is told', function () {
    Queue::fake();
    $this->accounts->revokeFails = true;

    $this->post(route('accounts.logout'))->assertRedirect('/')->assertSessionHas('status', fn ($status) => str_contains($status, 'lo reintentaremos'));

    $this->assertGuest();
    Queue::assertPushed(RevokeAccountsAccess::class, fn ($job) => ! str_contains($job->encryptedToken, 'refresh-initial'));
});

test('logging out while Accounts is down still ends the session and queues the revocation', function () {
    Queue::fake();
    $this->accounts->down = true;

    $this->post(route('accounts.logout'))->assertRedirect('/');

    $this->assertGuest();
    Queue::assertPushed(RevokeAccountsAccess::class);
});

test('the retry job revokes once Accounts is back', function () {
    $job = new RevokeAccountsAccess(Illuminate\Support\Facades\Crypt::encryptString('refresh-initial'));

    $job->handle(app(LuisML\AccountsClient\Actions\AccountsHttp::class));

    expect($this->accounts->called('POST /oauth/revoke'))->toBe(1);
});

test('local users are never found by email', function () {
    User::create(['name' => 'Impostor', 'email' => 'ana@example.test', 'password' => 'x']);

    $this->flushSession();
    auth()->logout();
    $this->login();

    expect(User::count())->toBe(2)->and(User::where('accounts_sub', 'subject-1')->firstOrFail()->name)->toBe('Ana Pérez');
});

test('lenient mode leaves other kinds of login alone but still controls Accounts sessions', function () {
    $this->app['router']->middleware(['web', 'accounts.access:lenient'])->get('/mixed', fn () => 'mixed');

    $this->get('/mixed')->assertOk();

    $this->travel(61)->seconds();
    $this->accounts->introspectionActive = false;
    $this->get('/mixed')->assertRedirect(route('accounts.login'));
    $this->assertGuest();

    auth()->logout();
    $this->flushSession();
    $this->actingAs(User::create(['name' => 'Local', 'email' => 'local@example.test', 'password' => 'x']));

    $this->get('/mixed')->assertOk();
});
