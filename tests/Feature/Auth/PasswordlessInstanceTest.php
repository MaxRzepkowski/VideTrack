<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

// PASSWORD_LOGIN_ENABLED=false: identity providers are the only way in, as
// long as there is one.

test('with a provider configured the password closes everywhere', function () {
    config(['tracker.auth.password_login' => false]);
    enableFakeIdentityProvider();
    $user = User::factory()->create();

    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('passwordLogin', false)
        ->where('registrationEnabled', false)
        ->where('canResetPassword', false));
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertNotFound();
    $this->get(route('register'))->assertNotFound();
    $this->get(route('password.request'))->assertNotFound();
    $this->assertGuest();

    $this->actingAs($user)->get(route('settings.password.edit'))->assertNotFound();
});

test('with no provider configured the password stays, so the admin still signs in', function () {
    config([
        'tracker.auth.password_login' => false,
        'tracker.admin_email' => 'admin@example.com',
        'tracker.admin_password' => 'a-long-admin-password',
    ]);

    $this->artisan('app:ensure-admin')
        ->expectsOutputToContain('PASSWORD_LOGIN_ENABLED=false is ignored')
        ->assertSuccessful();

    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->where('passwordLogin', true));
    $this->post(route('login'), ['email' => 'admin@example.com', 'password' => 'a-long-admin-password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('the start says nothing when passwords sign in as usual', function () {
    config(['tracker.admin_email' => null]);

    $this->artisan('app:ensure-admin')
        ->doesntExpectOutputToContain('PASSWORD_LOGIN_ENABLED')
        ->assertSuccessful();
});

test('an account without a password is never signed in by the password form', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => ''])->assertSessionHasErrors();
    $this->post(route('login'), ['email' => $user->email, 'password' => 'anything'])->assertSessionHasErrors('email');

    $this->assertGuest();
});
