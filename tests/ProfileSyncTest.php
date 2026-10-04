<?php

use LuisML\AccountsClient\Tests\Fixtures\User;

/**
 * Let the 60-second validation window pass so the next protected request asks Accounts again.
 */
function nextValidation(): void
{
    test()->travel(61)->seconds();
}

test('the account address is the Accounts account page unless configured', function () {
    expect(accounts_account_url())->toBe('https://accounts.example.test/account');

    config(['accounts.account_url' => 'https://perfil.example.test/me']);

    expect(accounts_account_url())->toBe('https://perfil.example.test/me');
});

test('a changed profile in Accounts reaches the local user on the next validation', function () {
    $this->login();
    $this->get('/protected')->assertOk();
    expect(User::firstOrFail()->name)->toBe('Ana Pérez');

    $this->accounts->name = 'Ana María Pérez';
    $this->accounts->profileVersion = 'profile-v2';
    nextValidation();

    $this->get('/protected')->assertOk();

    expect(User::firstOrFail()->name)->toBe('Ana María Pérez')
        ->and(session('accounts.session')['profile_version'])->toBe('profile-v2')
        ->and(User::count())->toBe(1);
});

test('an unchanged profile is read once per session and never again', function () {
    $this->login();
    nextValidation();
    $this->get('/protected')->assertOk();
    $reads = $this->accounts->called('GET /oauth/userinfo');

    nextValidation();
    $this->get('/protected')->assertOk();

    expect($this->accounts->called('GET /oauth/userinfo'))->toBe($reads);
});

test('a failed refresh does not break the request and is tried again at the next validation', function () {
    $this->login();
    $this->get('/protected')->assertOk();

    $this->accounts->name = 'Ana María Pérez';
    $this->accounts->profileVersion = 'profile-v2';
    $this->accounts->userInfoFails = true;
    nextValidation();

    $this->get('/protected')->assertOk();
    expect(User::firstOrFail()->name)->toBe('Ana Pérez');

    $this->accounts->userInfoFails = false;
    nextValidation();
    $this->get('/protected')->assertOk();

    expect(User::firstOrFail()->name)->toBe('Ana María Pérez');
});
