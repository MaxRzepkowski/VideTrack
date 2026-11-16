<?php

use App\Accounts\Identity\IdentityRefusal;
use App\Accounts\Identity\IdentityRefused;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\ServiceUnavailable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Fakes\FakeIdentityProvider;

function signInWithFake(): TestResponse
{
    return test()->get(route('identities.callback', FakeIdentityProvider::CODE, absolute: false).'?code=testing-code&state=testing-state');
}

test('without a configured provider the login page offers none', function () {
    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('identityProviders', [])
        ->where('passwordLogin', true));
});

test('a configured provider is offered on the login page', function () {
    enableFakeIdentityProvider();

    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('identityProviders', [['code' => FakeIdentityProvider::CODE, 'label' => 'Fake ID', 'glyph' => 'openid']]));
});

test('the trip to the provider comes back to the address from APP_URL', function () {
    enableFakeIdentityProvider();
    config(['app.url' => 'https://tracker.example.com']);

    $this->get(route('identities.redirect', FakeIdentityProvider::CODE))->assertRedirect('https://id.example.test/authorize');

    expect(FakeIdentityProvider::$callbackUrl)->toBe('https://tracker.example.com/auth/fake-identity/callback');
});

test('a provider this instance does not offer is not found', function () {
    $this->get(route('identities.redirect', 'nowhere'))->assertNotFound();
    $this->get(route('identities.callback', 'nowhere'))->assertNotFound();
});

test('a linked identity signs in to its account and lands where the person was headed', function () {
    $user = User::factory()->create();
    fakeIdentityOf($user);
    enableFakeIdentityProvider(identityClaims(email: 'new-address@example.com'));

    $this->get(route('lists.index'))->assertRedirect(route('login'));

    signInWithFake()->assertRedirect(route('lists.index'));

    $this->assertAuthenticatedAs($user);
    expect($user->identities()->sole()->email)->toBe('new-address@example.com');
});

test('a blocked account does not sign in through a provider either', function () {
    fakeIdentityOf(User::factory()->blocked()->create());
    enableFakeIdentityProvider(identityClaims());

    signInWithFake()
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors([IdentityRefused::FIELD => trans('auth.blocked')]);

    $this->assertGuest();
});

test('an address never joins an account, however the provider vouches for it', function (bool $verified, string $email) {
    $user = User::factory()->create(['email' => 'alex@example.com']);
    enableFakeIdentityProvider(identityClaims(email: $email, emailVerified: $verified));

    signInWithFake()->assertSessionHasErrors([IdentityRefused::FIELD => IdentityRefusal::EmailTaken->reason()]);

    $this->assertGuest();
    expect($user->identities()->exists())->toBeFalse()
        ->and(User::query()->count())->toBe(1);
})->with([
    'verified' => [true, 'alex@example.com'],
    'unverified' => [false, 'alex@example.com'],
    'in capitals' => [true, 'Alex@Example.com'],
]);

test('a newcomer\'s address is kept in lower case, as the sign-up form keeps it', function () {
    enableFakeIdentityProvider(identityClaims(email: 'Alex@Example.com'));

    signInWithFake();

    expect(User::query()->sole()->email)->toBe('alex@example.com');
});

test('where no password signs in, a taken address is not sent to log in with a password', function () {
    config(['tracker.auth.password_login' => false]);
    User::factory()->create(['email' => 'alex@example.com']);
    enableFakeIdentityProvider(identityClaims());

    signInWithFake()->assertSessionHasErrors([IdentityRefused::FIELD => IdentityRefusal::EmailTakenWithoutPassword->reason()]);
});

test('a newcomer gets an account without a password while sign-up is open', function () {
    User::factory()->create();
    enableFakeIdentityProvider(identityClaims());

    signInWithFake()->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'alex@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->hasPassword())->toBeFalse()
        ->and($user->username)->toBe('alex')
        ->and($user->name)->toBe('Alex')
        ->and($user->role)->toBe(UserRole::User)
        ->and($user->identities()->sole()->subject)->toBe('subject-1')
        ->and($user->watchlist()->exists)->toBeTrue();
});

test("a fresh instance's first account made through a provider runs it", function () {
    config(['tracker.registration_enabled' => null]);
    enableFakeIdentityProvider(identityClaims());

    signInWithFake();

    expect(User::query()->sole()->role)->toBe(UserRole::Admin);
});

test('a taken username is numbered, and a handle too short for one falls back', function () {
    User::factory()->create(['username' => 'alex']);
    enableFakeIdentityProvider(identityClaims());

    signInWithFake();
    expect(User::query()->where('email', 'alex@example.com')->value('username'))->toBe('alex_2');

    $this->post(route('logout'));
    FakeIdentityProvider::$comesBack = identityClaims(subject: 'subject-2', email: 'x@example.com', name: null, username: null);
    signInWithFake();

    $user = User::query()->where('email', 'x@example.com')->sole();
    expect($user->username)->toBe('user')->and($user->name)->toBe('user');
});

test('a newcomer gets no account while sign-up is closed', function () {
    config(['tracker.registration_enabled' => false]);
    enableFakeIdentityProvider(identityClaims());

    signInWithFake()->assertSessionHasErrors([IdentityRefused::FIELD => IdentityRefusal::NoAccount->reason()]);

    $this->assertGuest();
    expect(User::query()->exists())->toBeFalse();
});

test('a closed sign-up still lets an existing account in through a provider', function () {
    config(['tracker.registration_enabled' => false]);
    $user = User::factory()->create();
    fakeIdentityOf($user);
    enableFakeIdentityProvider(identityClaims());

    signInWithFake();

    $this->assertAuthenticatedAs($user);
});

test('a newcomer whose address the provider did not verify gets no account', function (?string $email, bool $verified) {
    enableFakeIdentityProvider(identityClaims(email: $email, emailVerified: $verified));

    signInWithFake()->assertSessionHasErrors([IdentityRefused::FIELD => IdentityRefusal::EmailUnverified->reason()]);

    expect(User::query()->exists())->toBeFalse();
})->with([
    'unverified' => ['alex@example.com', false],
    'no address at all' => [null, false],
]);

test('someone signed in comes back with the identity connected to their own account', function () {
    $viewer = User::factory()->create();
    enableFakeIdentityProvider(identityClaims());

    $this->actingAs($viewer);
    signInWithFake()->assertRedirect(route('settings.identities.index'));

    $this->assertAuthenticatedAs($viewer);
    expect($viewer->identities()->sole()->subject)->toBe('subject-1');
});

test('someone signed in is never switched to the account an identity belongs to', function () {
    $owner = User::factory()->create();
    fakeIdentityOf($owner);
    $viewer = User::factory()->create();
    enableFakeIdentityProvider(identityClaims());

    $this->actingAs($viewer);
    signInWithFake()
        ->assertRedirect(route('settings.identities.index'))
        ->assertSessionHasErrors([IdentityRefused::FIELD => IdentityRefusal::LinkedElsewhere->reason()]);

    $this->assertAuthenticatedAs($viewer);
    expect($viewer->identities()->exists())->toBeFalse();
});

test('an account connects one identity per provider', function () {
    $viewer = User::factory()->create();
    fakeIdentityOf($viewer, 'subject-old');
    enableFakeIdentityProvider(identityClaims(subject: 'subject-new'));

    $this->actingAs($viewer);
    signInWithFake()->assertSessionHasErrors([IdentityRefused::FIELD => IdentityRefusal::AlreadyConnected->reason()]);

    expect($viewer->identities()->sole()->subject)->toBe('subject-old');
});

test('connecting the identity already connected changes nothing', function () {
    $viewer = User::factory()->create();
    fakeIdentityOf($viewer);
    enableFakeIdentityProvider(identityClaims());

    $this->actingAs($viewer);
    signInWithFake()->assertSessionHasNoErrors();

    expect($viewer->identities()->count())->toBe(1);
});

test('a person who said no at the provider is told so', function () {
    enableFakeIdentityProvider(identityClaims());

    $this->get(route('identities.callback', ['provider' => FakeIdentityProvider::CODE, 'error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors([IdentityRefused::FIELD => IdentityRefusal::Cancelled->reason()]);

    $this->assertGuest();
});

test('a provider that fails sends the person back with the reason', function (string $route) {
    enableFakeIdentityProvider();
    FakeIdentityProvider::$fails = new ServiceUnavailable('account.identity_server_unreachable');

    $this->get(route($route, FakeIdentityProvider::CODE))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors([IdentityRefused::FIELD => trans('account.identity_server_unreachable')]);

    $this->assertGuest();
})->with(['identities.redirect', 'identities.callback']);

test('the trips to providers have a ceiling per address', function () {
    enableFakeIdentityProvider();
    $limit = config()->integer('tracker.rate_limits.identity_sign_in');

    foreach (range(1, $limit) as $trip) {
        $this->get(route('identities.redirect', FakeIdentityProvider::CODE))->assertRedirect();
    }

    $this->get(route('identities.redirect', FakeIdentityProvider::CODE))->assertTooManyRequests();
});
