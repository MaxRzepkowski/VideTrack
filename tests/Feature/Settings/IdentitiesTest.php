<?php

use App\Accounts\Identity\IdentityRefusal;
use App\Accounts\Identity\IdentityRefused;
use App\Models\Identity;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\Fakes\FakeIdentityProvider;

test('without a configured provider there is no page of connected accounts', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.identities.index'))
        ->assertNotFound();
});

test('the page lists the identities the user signs in with', function () {
    enableFakeIdentityProvider();
    $user = User::factory()->create();
    $identity = fakeIdentityOf($user);
    fakeIdentityOf(User::factory()->create(), 'subject-2');

    $this->actingAs($user)
        ->get(route('settings.identities.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Identities')
            ->where('identities', [[
                'id' => $identity->id,
                'provider' => FakeIdentityProvider::CODE,
                'email' => 'alex@example.com',
            ]]));
});

test('an identity is disconnected while a password still signs in', function () {
    enableFakeIdentityProvider();
    $user = User::factory()->create();
    $identity = fakeIdentityOf($user);

    $this->actingAs($user)
        ->delete(route('settings.identities.destroy', $identity))
        ->assertSessionHasNoErrors();

    expect($identity->fresh())->toBeNull();
});

test('somebody else\'s identity is not found', function () {
    enableFakeIdentityProvider();
    $identity = fakeIdentityOf(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->delete(route('settings.identities.destroy', $identity))
        ->assertNotFound();

    expect($identity->fresh())->not->toBeNull();
});

test('the last way in is never disconnected', function (bool $hasPassword, bool $passwordLogin) {
    config(['tracker.auth.password_login' => $passwordLogin]);
    enableFakeIdentityProvider();
    $user = $hasPassword ? User::factory()->create() : User::factory()->withoutPassword()->create();
    $identity = fakeIdentityOf($user);
    // An identity at a provider no longer configured opens nothing.
    Identity::factory()->for($user)->create(['provider' => 'gone']);

    $this->actingAs($user)
        ->from(route('settings.identities.index'))
        ->delete(route('settings.identities.destroy', $identity))
        ->assertRedirect(route('settings.identities.index'))
        ->assertSessionHasErrors([IdentityRefused::FIELD => IdentityRefusal::LastSignInMethod->reason()]);

    expect($identity->fresh())->not->toBeNull();
})->with([
    'an account without a password' => [false, true],
    'a password where passwords do not sign in' => [true, false],
]);

test('an account without a password sets its first one without a current one', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user)
        ->put(route('settings.password.update'), ['password' => 'new-password', 'password_confirmation' => 'new-password'])
        ->assertSessionHasNoErrors();

    expect(Hash::check('new-password', (string) $user->refresh()->password))->toBeTrue();
});

test('an account with a password still has to give the current one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.password.update'), ['password' => 'new-password', 'password_confirmation' => 'new-password'])
        ->assertSessionHasErrors('current_password');
});

test('every page tells whether the viewer confirms with a password', function (bool $hasPassword) {
    $user = $hasPassword ? User::factory()->create() : User::factory()->withoutPassword()->create();

    $this->actingAs($user)
        ->get(route('settings.profile.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.password_sign_in', $hasPassword));
})->with(['a password' => true, 'no password' => false]);

test('an account without a password confirms clearing its data and deleting itself with its username', function (string $route) {
    $user = User::factory()->withoutPassword()->create(['username' => 'alex']);

    $this->actingAs($user)
        ->delete(route($route), ['username' => 'someone_else'])
        ->assertSessionHasErrors(['username' => trans('settings.username_does_not_match')]);
    expect($user->fresh())->not->toBeNull();

    $this->actingAs($user)
        ->delete(route($route), ['username' => 'alex'])
        ->assertSessionHasNoErrors();
})->with(['settings.data.destroy', 'settings.profile.destroy']);

test('deleting an account takes its identities with it', function () {
    $user = User::factory()->withoutPassword()->create(['username' => 'alex']);
    $identity = fakeIdentityOf($user);

    $this->actingAs($user)->delete(route('settings.profile.destroy'), ['username' => 'alex']);

    expect($user->fresh())->toBeNull()->and($identity->fresh())->toBeNull();
});
