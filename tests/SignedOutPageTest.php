<?php

test('the signed-out page is public and does not redirect anywhere', function () {
    $this->get(route('accounts.signed-out'))->assertOk()->assertSee('<h1>', false);
});

test('it also renders for someone who is still signed in', function () {
    $this->login();

    $this->get(route('accounts.signed-out'))->assertOk();
});

test('it offers to sign in again and to use another account', function () {
    $this->get(route('accounts.signed-out'))
        ->assertSee(route('accounts.login'), false)
        ->assertSee(route('accounts.login', ['prompt' => 'select_account']), false)
        ->assertSee('Entrar con LuisML')
        ->assertSee('Usar otra cuenta');
});

test('without any context the page says the session ended in this application', function () {
    $this->get(route('accounts.signed-out'))
        ->assertSee('data-status="local"', false)
        ->assertSee('Cerraste sesión en esta aplicación.');
});

test('each state shows its own message', function (string $status, string $message, bool $withFinishLink) {
    $response = $this->withSession(['accounts.logout' => ['status' => $status, 'subject' => 'subject-1']])
        ->get(route('accounts.signed-out'))
        ->assertOk()
        ->assertSee('data-status="'.$status.'"', false)
        ->assertSee($message);

    $withFinishLink
        ? $response->assertSee('Completar cierre')
        : $response->assertDontSee('Completar cierre');
})->with([
    'local' => ['local', 'Cerraste sesión en esta aplicación.', false],
    'pending' => ['central_pending', 'no pudimos confirmarlo', true],
]);

test('when Accounts cannot be reached the page offers to try again instead of a link it cannot build', function () {
    \Illuminate\Support\Facades\Cache::flush();
    $this->accounts->down = true;

    $this->withSession(['accounts.logout' => ['status' => 'central_pending', 'subject' => 'subject-1']])
        ->get(route('accounts.signed-out'))
        ->assertOk()
        ->assertSee('data-status="central_pending"', false)
        ->assertDontSee('end-session', false)
        ->assertSee('Reintentar');
});
