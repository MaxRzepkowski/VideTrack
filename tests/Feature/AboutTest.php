<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/** The names the About page credits, in its order. */
function creditedNames(Assert $page): array
{
    return array_column($page->toArray()['props']['dataSources'], 'name');
}

const ALWAYS_CREDITED = ['The Movie Database (TMDB)', 'JustWatch'];

const ALWAYS_LAST = ['TVmaze', 'Wikidata'];

test('the about page renders with the app version', function () {
    config(['tracker.version' => '1.2.3']);

    $this->actingAs(User::factory()->create())
        ->get('/about')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => expect(creditedNames($page->component('About')->where('version', '1.2.3')->where('sourceUrl', config('tracker.source_url'))))
            ->toBe([...ALWAYS_CREDITED, ...ALWAYS_LAST]));
});

test('about credits only enabled optional modules', function (string $enable, string $source) {
    $enable();

    $this->actingAs(User::factory()->create())
        ->get('/about')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => expect(creditedNames($page))->toBe([...ALWAYS_CREDITED, $source, ...ALWAYS_LAST]));
})->with([
    'games' => ['enableGamesModule', 'IGDB'],
    'books' => ['enableBooksModule', 'Hardcover'],
    'manga' => ['enableMangaModule', 'MyAnimeList'],
    'comics' => ['enableComicsModule', 'Comic Vine'],
]);

test('about includes all enabled data sources in display order', function () {
    enableGamesModule();
    enableBooksModule();
    enableMangaModule();
    enableComicsModule();

    $this->actingAs(User::factory()->create())
        ->get('/about')
        ->assertInertia(fn (Assert $page) => expect(creditedNames($page))
            ->toBe([...ALWAYS_CREDITED, 'IGDB', 'Hardcover', 'MyAnimeList', 'Comic Vine', ...ALWAYS_LAST]));
});

test('a credit reads in the reader language and names its licence', function () {
    $this->actingAs(User::factory()->create())
        ->get('/about')
        ->assertInertia(fn (Assert $page) => $page
            ->where('dataSources.0.notices.0', __('app.this_product_uses_the_tmdb_api'))
            ->where('dataSources.0.logo', '/images/tmdb.svg')
            ->where('dataSources.2.licenseName', 'CC BY-SA'));
});

test('guests are redirected to login', function () {
    $this->get('/about')->assertRedirect('/login');
});
