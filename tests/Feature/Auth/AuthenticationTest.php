<?php

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

test('the login screen is the login page', function () {
    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login'));
});

test('a user signs in with the right password and lands on the dashboard', function () {
    $user = User::factory()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('a wrong password signs nobody in', function () {
    $user = User::factory()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

test('after five wrong passwords even the right one is refused for a while', function () {
    $user = User::factory()->create();

    foreach (range(1, LoginRequest::ATTEMPTS_PER_ADDRESS) as $attempt) {
        $this->post(route('login'), ['email' => $user->email, 'password' => "wrong-{$attempt}"]);
    }

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->travel(2)->minutes();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});

test('a user signs out', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect('/');

    $this->assertGuest();
});

test('one address trying every e-mail in turn is stopped', function () {
    $limit = config()->integer('tracker.rate_limits.login_per_ip');

    foreach (range(1, $limit) as $attempt) {
        $this->post(route('login'), ['email' => "guess{$attempt}@example.com", 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    $this->post(route('login'), ['email' => 'one-more@example.com', 'password' => 'wrong'])->assertTooManyRequests();
});

test('a visitor from the internet cannot pick a new address for each guess with X-Forwarded-For', function () {
    $user = User::factory()->create();

    foreach (range(1, LoginRequest::ATTEMPTS_PER_ADDRESS + 1) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->post(route('login'), ['email' => $user->email, 'password' => "wrong-{$attempt}"], ['X-Forwarded-For' => "198.51.100.{$attempt}"]);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->post(route('login'), ['email' => $user->email, 'password' => 'password'], ['X-Forwarded-For' => '198.51.100.99'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('a proxy on a private address passes on the client address and the scheme', function () {
    $seen = null;
    Route::get('/_probe', function (Request $request) use (&$seen) {
        $seen = [$request->ip(), $request->isSecure()];

        return response()->noContent();
    });

    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.2'])
        ->get('/_probe', ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https']);

    expect($seen)->toBe(['203.0.113.7', true]);
});

test('an account meets a wall after its hourly count of wrong passwords, from however many addresses', function () {
    $user = User::factory()->create();
    $limit = config()->integer('tracker.rate_limits.login_per_account');

    foreach (range(1, $limit) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$attempt])
            ->post(route('login'), ['email' => $user->email, 'password' => 'wrong']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
        ->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});
