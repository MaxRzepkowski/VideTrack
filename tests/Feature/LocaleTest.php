<?php

use App\Models\User;
use Illuminate\Support\Facades\App;

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

test('a page names its language the way Intl takes it', function () {
    $user = User::factory()->create(['locale' => 'pt_BR']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('locale', 'pt_BR')->where('languageTag', 'pt-BR'));
});

test('a page declares its language as the locale is written, a region only where the language has one', function (string $locale, string $tag) {
    $this->actingAs(User::factory()->create(['locale' => $locale]))
        ->get(route('dashboard'))
        ->assertSee('<html lang="'.$tag.'"', false);
})->with([
    'Polish' => ['pl', 'pl'],
    'Brazilian Portuguese' => ['pt_BR', 'pt-BR'],
]);

test('a page outside the app declares its language the same way', function () {
    App::setLocale('pt_BR');

    $this->get('/up')->assertSee('<html lang="pt-BR"', false);
});

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
