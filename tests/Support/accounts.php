<?php

use App\Accounts\Identity\Data\IdentityClaims;
use App\Accounts\Identity\IdentityProviderRegistry;
use App\Models\Identity;
use App\Models\User;
use Tests\Fakes\FakeIdentityProvider;

/**
 * What the sign-up form sends for a valid new account; a test overrides the
 * field it is about.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function registrationForm(array $overrides = []): array
{
    return [
        'name' => 'Test User',
        'username' => 'test_user',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$overrides,
    ];
}

/**
 * Offers the fake identity provider on the sign-in page for one test (the
 * registry is a singleton resolved lazily, so registering it first is
 * enough), sending back $claims.
 */
function enableFakeIdentityProvider(?IdentityClaims $claims = null): void
{
    app(IdentityProviderRegistry::class)->register(FakeIdentityProvider::CODE, FakeIdentityProvider::class);
    FakeIdentityProvider::$comesBack = $claims;
}

/**
 * Who the fake provider says came back; a test overrides what it is about.
 */
function identityClaims(
    string $subject = 'subject-1',
    ?string $email = 'alex@example.com',
    bool $emailVerified = true,
    ?string $name = 'Alex',
    ?string $username = 'alex',
): IdentityClaims {
    return new IdentityClaims(FakeIdentityProvider::CODE, $subject, $email, $emailVerified, $name, $username);
}

/** An identity at the fake provider that signs in to $user. */
function fakeIdentityOf(User $user, string $subject = 'subject-1'): Identity
{
    return Identity::factory()->for($user)->create(['provider' => FakeIdentityProvider::CODE, 'subject' => $subject, 'email' => 'alex@example.com']);
}
