<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => Notification::fake());

/** Asks for a reset link and hands back the token the mail would carry. */
function requestResetToken(User $user): string
{
    test()->post(route('password.email'), ['email' => $user->email]);

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    return $token;
}

test('the reset link screen is the forgot password page', function () {
    $this->get(route('password.request'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/ForgotPassword'));
});

test('the reset link screen says when the server sends no mail', function () {
    config(['mail.default' => 'log']);
    $this->get(route('password.request'))->assertInertia(fn (AssertableInertia $page) => $page->where('sendsMail', false));

    config(['mail.default' => 'smtp']);
    $this->get(route('password.request'))->assertInertia(fn (AssertableInertia $page) => $page->where('sendsMail', true));
});

test('a reset link goes to the account', function () {
    requestResetToken(User::factory()->create());
});

test('an address with no account gets the same answer and no mail, so accounts cannot be told apart', function () {
    $user = User::factory()->create();

    $known = $this->post(route('password.email'), ['email' => $user->email]);
    $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.com']);

    $unknown->assertSessionHasNoErrors()->assertSessionHas('status', $known->getSession()->get('status'));
    Notification::assertSentTimes(ResetPassword::class, 1);
});

test('the link opens the reset password page', function () {
    $token = requestResetToken(User::factory()->create());

    $this->get(route('password.reset', ['token' => $token]))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/ResetPassword'));
});

test('the token from the mail sets a new password, stored hashed', function () {
    $user = User::factory()->create();
    $token = requestResetToken($user);

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect(Hash::check('brand-new-secret', $user->refresh()->password))->toBeTrue();
});

test('a reset revokes the account\'s API tokens, since whoever held the account could have minted one', function () {
    $user = User::factory()->create();
    $user->createToken('left-behind');
    $token = requestResetToken($user);

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ])->assertSessionHasNoErrors();

    expect($user->tokens()->count())->toBe(0);
});

test('a wrong token changes nothing', function () {
    $user = User::factory()->create();
    requestResetToken($user);

    $this->post(route('password.store'), [
        'token' => 'not-the-token',
        'email' => $user->email,
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

test('a token past its hour changes nothing', function () {
    $user = User::factory()->create();
    $token = requestResetToken($user);

    $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

test('the mailed link points at the instance, whatever host the request named', function () {
    config(['app.url' => 'https://tracker.example.com']);
    $user = User::factory()->create();

    // The sender picks Host and X-Forwarded-Host; a root URL taken from them
    // is what the link would otherwise be built on.
    URL::forceRootUrl('https://evil.example');
    $this->post(route('password.email'), ['email' => $user->email], ['X-Forwarded-Host' => 'evil.example']);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $link = $notification->toMail($user)->actionUrl;

        return $link === 'https://tracker.example.com'.route('password.reset', ['token' => $notification->token, 'email' => $user->email], absolute: false);
    });
});
