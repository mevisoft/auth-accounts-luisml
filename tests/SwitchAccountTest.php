<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use LuisML\AccountsClient\Actions\AccountsHttp;
use LuisML\AccountsClient\Jobs\RevokeAccountsAccess;

test('switching ends only this application and sends the person to the account chooser', function () {
    $this->login();

    $response = $this->post(route('accounts.switch'));

    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith(route('accounts.login'))
        ->and($query['prompt'])->toBe('select_account')
        ->and($query)->not->toHaveKey('login_hint')
        ->and($this->accounts->called('POST /oauth/revoke'))->toBe(1)
        ->and(session('accounts.session'))->toBeNull()
        ->and($response->getCookie('accounts_logout'))->toBeNull();
    $this->assertGuest();
});

test('a login hint is passed on to the chooser', function () {
    $this->login();

    $this->post(route('accounts.switch'), ['login_hint' => 'ana@example.test'])
        ->assertRedirect(route('accounts.login', ['prompt' => 'select_account', 'login_hint' => 'ana@example.test']));
});

test('a guest is sent to sign in and switching is not reachable by GET', function () {
    $this->post(route('accounts.switch'))->assertRedirect();
    $this->get(route('accounts.switch'))->assertMethodNotAllowed();
});

test('an unconfirmed revocation is queued for retry and the local session is gone anyway', function () {
    $this->login();
    Queue::fake();
    config(['queue.default' => 'database']);
    $this->accounts->revokeFails = true;

    $this->post(route('accounts.switch'))->assertRedirect();

    Queue::assertPushed(RevokeAccountsAccess::class);
    $this->assertGuest();
});

test('the switch is written to the security log without personal data', function () {
    $this->login();
    Log::spy();

    $this->post(route('accounts.switch'));

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'accounts.switched'
        && $context['account'] === 'subject-1'
        && $context['client_id'] === 'client-id-1'
        && $context['operation'] === 'switch'
        && $context['result'] === 'success'
        && ! empty($context['correlation_id'])
        && ! str_contains(json_encode($context), 'ana@example.test'));
});

test('signing in again as the same account before the queued revocation runs is not undone by it', function () {
    $queuedAt = now()->getTimestamp();
    $token = Crypt::encryptString('token');

    $this->login();
    $this->post(route('accounts.switch'));
    $this->travel(5)->seconds();
    $this->login();

    (new RevokeAccountsAccess($token, 'subject-1', $queuedAt))->handle(app(AccountsHttp::class));

    expect($this->accounts->called('POST /oauth/revoke'))->toBe(1)
        ->and(session('accounts.session'))->not->toBeNull();
    $this->assertAuthenticated();
});
