<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use LuisML\AccountsClient\Actions\AccountsHttp;
use LuisML\AccountsClient\Jobs\RevokeAccountsAccess;

beforeEach(function () {
    config(['accounts.global_logout' => true, 'accounts.post_logout_redirect' => null]);
    $this->login();
});

/**
 * @return array<string, string>
 */
function centralLogoutQuery(string $location): array
{
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

test('the local session ends whatever else fails', function () {
    $this->accounts->revokeFails = true;
    Cache::flush();
    $this->accounts->down = true;

    $this->post(route('accounts.logout'))->assertRedirect();

    $this->assertGuest();
    expect(session('accounts.session'))->toBeNull();
});

test('with an ID Token the person is sent to end the central session, with a state the app remembers', function () {
    $response = $this->post(route('accounts.logout'));

    $location = $response->headers->get('Location');
    $query = centralLogoutQuery($location);
    $remembered = json_decode($response->getCookie('accounts_logout')->getValue(), true);

    expect($location)->toStartWith('https://accounts.example.test/oauth/end-session?')
        ->and($query['id_token_hint'])->toContain('eyJ')
        ->and($query['post_logout_redirect_uri'])->toBe(route('accounts.signed-out'))
        ->and($query['state'])->not->toBeEmpty()
        ->and($remembered['state'])->toBe($query['state'])
        ->and($remembered['subject'])->toBe('subject-1');
});

test('without an ID Token the close is reported as pending and never as done', function () {
    $stored = session('accounts.session');
    $stored['id_token'] = null;
    session(['accounts.session' => $stored]);

    $this->post(route('accounts.logout'))
        ->assertRedirect(route('accounts.signed-out'))
        ->assertSessionHas('accounts.logout', fn (array $logout): bool => $logout === ['status' => 'central_pending', 'subject' => 'subject-1']);

    $this->assertGuest();
});

test('with Accounts unreachable the close is reported as pending', function () {
    Cache::flush();
    $this->accounts->down = true;

    $this->post(route('accounts.logout'))
        ->assertRedirect(route('accounts.signed-out'))
        ->assertSessionHas('accounts.logout', fn (array $logout): bool => $logout['status'] === 'central_pending');
});

test('with global logout off the close is local', function () {
    config(['accounts.global_logout' => false]);

    $this->post(route('accounts.logout'))
        ->assertRedirect(route('accounts.signed-out'))
        ->assertSessionHas('accounts.logout', fn (array $logout): bool => $logout['status'] === 'local');

    $this->assertGuest();
});

test('the signed-out page says done only when the central logout comes back with the state the app started', function () {
    $response = $this->post(route('accounts.logout'));
    $state = centralLogoutQuery($response->headers->get('Location'))['state'];

    $this->withCookie('accounts_logout', $response->getCookie('accounts_logout')->getValue())
        ->get(route('accounts.signed-out', ['state' => $state]))
        ->assertOk()
        ->assertSee('data-status="done"', false);
});

test('a state that is missing or different leaves the close pending', function (?string $returned) {
    $response = $this->post(route('accounts.logout'));

    $this->withCookie('accounts_logout', $response->getCookie('accounts_logout')->getValue())
        ->get(route('accounts.signed-out', array_filter(['state' => $returned])))
        ->assertOk()
        ->assertSee('data-status="central_pending"', false)
        ->assertDontSee('data-status="done"', false);
})->with([
    'no state returned' => [null],
    'another state' => ['not-the-one-we-sent'],
]);

test('the link to finish the close names the account and the application but carries no ID Token', function () {
    $response = $this->withSession(['accounts.logout' => ['status' => 'central_pending', 'subject' => 'subject-1']])
        ->get(route('accounts.signed-out'))->assertOk();

    preg_match('/href="([^"]*end-session[^"]*)"/', $response->getContent(), $found);
    $query = centralLogoutQuery(html_entity_decode($found[1] ?? ''));
    $remembered = json_decode($response->getCookie('accounts_logout')->getValue(), true);

    expect($found[1] ?? '')->toStartWith('https://accounts.example.test/oauth/end-session?')
        ->and($query)->toMatchArray([
            'client_id' => 'client-id-1',
            'login_hint' => 'subject-1',
            'post_logout_redirect_uri' => route('accounts.signed-out'),
        ])
        ->and($query)->not->toHaveKey('id_token_hint')
        ->and($query['state'])->not->toBeEmpty()
        ->and($remembered['state'])->toBe($query['state']);
});

test('a pending close is written to the security log with the account, the application and a correlation id, and no personal data', function () {
    Log::spy();
    $stored = session('accounts.session');
    $stored['id_token'] = null;
    session(['accounts.session' => $stored]);

    $this->post(route('accounts.logout'));

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        return $message === 'accounts.logout.central_pending'
            && $context['account'] === 'subject-1'
            && $context['client_id'] === 'client-id-1'
            && $context['operation'] === 'logout'
            && $context['result'] === 'central_pending'
            && ! empty($context['correlation_id'])
            && ! str_contains(json_encode($context), 'ana@example.test');
    });
});

test('a revocation queued before the person signed in again is skipped, one queued after is not', function () {
    $token = Crypt::encryptString('token');

    (new RevokeAccountsAccess($token, 'subject-1', now()->subMinute()->getTimestamp()))->handle(app(AccountsHttp::class));
    expect($this->accounts->called('POST /oauth/revoke'))->toBe(0);

    (new RevokeAccountsAccess($token, 'subject-1', now()->addMinute()->getTimestamp()))->handle(app(AccountsHttp::class));
    expect($this->accounts->called('POST /oauth/revoke'))->toBe(1);
});

test('a revocation queued for another account is not skipped', function () {
    (new RevokeAccountsAccess(Crypt::encryptString('token'), 'someone-else', now()->subMinute()->getTimestamp()))->handle(app(AccountsHttp::class));

    expect($this->accounts->called('POST /oauth/revoke'))->toBe(1);
});
