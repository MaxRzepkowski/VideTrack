<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

test('the registration screen is the sign-up page', function () {
    $this->get(route('register'))->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Register'));
});

test('a new user registers and lands on the dashboard', function () {
    $this->post(route('register'), registrationForm())
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('a new account comes with its backlog, its calendar feed and a hashed password', function () {
    $this->post(route('register'), registrationForm());

    $user = User::query()->where('username', 'test_user')->firstOrFail();

    expect($user->watchlist()->title)->toBeNull()
        ->and(User::query()->withCalendarToken($user->calendarToken())->value('id'))->toBe($user->id)
        ->and(Hash::check('password', $user->password))->toBeTrue();
});

test('a username outside the allowed format is refused', function () {
    $this->post(route('register'), registrationForm(['username' => 'Bad Username!']))
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

test('a username already taken is refused', function () {
    User::factory()->create(['username' => 'taken_name']);

    $this->post(route('register'), registrationForm(['username' => 'taken_name']))
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

test('the first account runs the instance, so it becomes its administrator; the next one does not', function () {
    $this->post(route('register'), registrationForm());
    auth()->logout();
    $this->post(route('register'), [...registrationForm(), 'username' => 'second', 'email' => 'second@example.com']);

    expect(User::query()->where('username', 'test_user')->value('role'))->toBe(UserRole::Admin)
        ->and(User::query()->where('username', 'second')->value('role'))->toBe(UserRole::User);
});

test('left unset, sign-up is open until the first account exists', function () {
    config(['tracker.registration_enabled' => null]);

    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->where('registrationEnabled', true));
    $this->get(route('register'))->assertOk();

    User::factory()->create();

    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->where('registrationEnabled', false));
    $this->get(route('register'))->assertNotFound();
    $this->post(route('register'), registrationForm())->assertNotFound();
});

test('the operator decides either way once REGISTRATION_ENABLED is set', function () {
    User::factory()->create();

    config(['tracker.registration_enabled' => true]);
    $this->get(route('register'))->assertOk();

    config(['tracker.registration_enabled' => false]);
    $this->get(route('register'))->assertNotFound();
});
