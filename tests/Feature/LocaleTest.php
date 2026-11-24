<?php

use App\Models\User;

test('guests get the default locale from configuration', function () {
    config(['tracker.default_locale' => 'pl']);

    $this->get('/login')
        ->assertInertia(fn ($page) => $page->where('locale', 'pl'));
});

test('the locale of the logged in user wins over the default', function () {
    $user = User::factory()->create(['locale' => 'pl']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('locale', 'pl'));
});

test('an unsupported locale falls back to the application default', function () {
    $user = User::factory()->create(['locale' => 'xx']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('locale', 'en'));
});

test('a page says whether its language writes nouns with a capital', function (string $locale, bool $capitalized) {
    $user = User::factory()->create(['locale' => $locale]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('capitalizedNouns', $capitalized));
})->with([
    'English' => ['en', false],
    'German' => ['de', true],
]);

test('users can change their locale in profile settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/settings/profile', [
        'name' => $user->name,
        'username' => $user->username,
        'email' => $user->email,
        'locale' => 'pl',
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->locale)->toBe('pl');
});
