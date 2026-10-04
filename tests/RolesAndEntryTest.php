<?php

use LuisML\AccountsClient\Tests\Fixtures\User;

test('the roles Accounts assigns are stored at sign-in', function () {
    $this->accounts->roles = ['admin'];
    $this->login()->assertRedirect('/');

    $user = User::firstOrFail();

    expect($user->accountsRoles())->toBe(['admin'])
        ->and($user->hasAccountsRole('admin'))->toBeTrue()
        ->and($user->hasAccountsRole('other'))->toBeFalse();
});

test('a user without the roles claim has no roles, so nothing lingers', function () {
    $this->accounts->roles = ['admin'];
    $this->login();
    auth()->logout();
    $this->flushSession();

    $this->accounts->roles = null;
    $this->login();

    expect(User::firstOrFail()->accountsRoles())->toBe([]);
});

test('a role granted or withdrawn in Accounts reaches the local user on the next validation', function () {
    $this->accounts->roles = [];
    $this->login();
    $this->get('/protected')->assertOk();

    $this->accounts->roles = ['admin'];
    $this->accounts->profileVersion = 'profile-v2';
    $this->travel(61)->seconds();
    $this->get('/protected')->assertOk();

    expect(User::firstOrFail()->hasAccountsRole('admin'))->toBeTrue();

    $this->accounts->roles = [];
    $this->accounts->profileVersion = 'profile-v3';
    $this->travel(61)->seconds();
    $this->get('/protected')->assertOk();

    expect(User::firstOrFail()->hasAccountsRole('admin'))->toBeFalse();
});

test('a failed refresh keeps the last known roles', function () {
    $this->accounts->roles = ['admin'];
    $this->login();
    $this->get('/protected')->assertOk();

    $this->accounts->roles = [];
    $this->accounts->profileVersion = 'profile-v2';
    $this->accounts->userInfoFails = true;
    $this->travel(61)->seconds();
    $this->get('/protected')->assertOk();

    expect(User::firstOrFail()->hasAccountsRole('admin'))->toBeTrue();
});

test('the login address sends a guest straight to Accounts', function () {
    $this->get('/login')->assertRedirect(route('accounts.login'));
});

test('coming back to the login address right away shows a manual button instead of looping', function () {
    $this->get('/login')->assertRedirect(route('accounts.login'));

    $this->get('/login')
        ->assertOk()
        ->assertSee('Continuar con LuisML')
        ->assertSee(route('accounts.login'), false);
});

test('after the loop window the automatic redirect works again', function () {
    $this->get('/login')->assertRedirect(route('accounts.login'));
    $this->travel(31)->seconds();

    $this->get('/login')->assertRedirect(route('accounts.login'));
});

test('a protected page sends a guest to login, which sends them to Accounts', function () {
    $this->get('/protected')->assertRedirect(route('accounts.login'));
});

test('an Inertia visit to the login address is told to make a full visit, so it never follows a cross-origin redirect', function () {
    $response = $this->withHeader('X-Inertia', 'true')->get('/login');

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toBe(route('accounts.login'));
});
