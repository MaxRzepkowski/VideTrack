<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('the manga route is 404 when the manga module is not configured', function () {
    $this->actingAs(User::factory()->create())
        ->get('/manga/2')
        ->assertNotFound();
});

test('the comic route is 404 when the comics module is not configured', function () {
    $this->actingAs(User::factory()->create())
        ->get('/comic/4207')
        ->assertNotFound();
});

test('the manga details page renders with chapters, authors and the format', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga/2*' => Http::response(jsonFixture('mal/details'))]);

    $this->actingAs(User::factory()->create())
        ->get('/manga/2')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/MangaDetails')
            ->where('manga.title', 'Berserk')
            ->where('manga.type', 'manga')
            ->where('manga.extra.chapters', 380)
            ->where('manga.extra.volumes', 42)
            ->where('manga.extra.mangaFormat', 'manga')
            ->where('manga.extra.authors.0', 'Kentarou Miura')
        );
});

test('the comic details page renders with issues, creators and the publisher', function () {
    enableComicsModule();
    Http::fake(['comicvine.gamespot.com/api/volume/4050-4207/*' => Http::response(jsonFixture('comicvine/volume'))]);

    $this->actingAs(User::factory()->create())
        ->get('/comic/4207')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/ComicDetails')
            ->where('comic.title', 'The Sandman')
            ->where('comic.type', 'comic')
            ->where('comic.extra.issues', 75)
            ->where('comic.extra.publisher', 'Vertigo')
            ->where('comic.extra.creators.0', 'Neil Gaiman')
        );
});

test('search grows one scope per configured module plus a people scope, defaulting to the first', function () {
    enableMangaModule();
    enableComicsModule();
    Http::fake([
        '*/search/multi*' => Http::response(jsonFixture('tmdb/search')),
        'api.myanimelist.net/*' => Http::response(jsonFixture('mal/search')),
        'comicvine.gamespot.com/*' => Http::response(jsonFixture('comicvine/search')),
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/search?q=sandman')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/Search')
            // No "all" scope: the default targets the first provider only.
            ->where('scope', 'tmdb')
            ->where('scopes', [
                ['key' => 'tmdb', 'types' => ['show', 'movie']],
                ['key' => 'mal', 'types' => ['manga']],
                ['key' => 'comicvine', 'types' => ['comic']],
                ['key' => 'person', 'types' => ['person']],
                ['key' => 'collection', 'types' => ['collection']],
                ['key' => 'company', 'types' => ['company']],
            ])
            // Exact TMDB refs prove the default scope ran without merging
            // results from either optional media provider.
            ->where('results', fn ($results) => collect($results)->pluck('ref')->all() === ['603', '1399', '999'])
        );
});

test('a selected manga scope queries only MAL', function () {
    enableMangaModule();
    enableComicsModule();
    Http::fake([
        '*/search/multi*' => Http::response(jsonFixture('tmdb/search')),
        'api.myanimelist.net/*' => Http::response(jsonFixture('mal/search')),
        'comicvine.gamespot.com/*' => Http::response(jsonFixture('comicvine/search')),
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/search?q=berserk&scope=mal')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'mal')
            ->has('results', 2)
            ->where('results.0.type', 'manga')
        );

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'search/multi'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'comicvine.gamespot.com'));
});

test('discover offers a manga chip (trending) but no comic chip (no listings)', function () {
    enableMangaModule();
    enableComicsModule();
    Http::fake([
        'api.themoviedb.org/*' => Http::response(['results' => []]),
        'api.myanimelist.net/*' => Http::response(jsonFixture('mal/ranking')),
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/discover')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/Discover')
            ->where('availableTypes', fn ($types) => collect($types)->contains('manga') && ! collect($types)->contains('comic'))
        );
});

test('the manga discover filter serves the MAL popularity ranking as trending', function () {
    enableMangaModule();
    Http::fake([
        'api.themoviedb.org/*' => Http::response(['results' => []]),
        'api.myanimelist.net/*' => Http::response(jsonFixture('mal/ranking')),
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/discover?type=manga')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/Discover')
            ->has('trending', 2)
            ->where('trending.0.type', 'manga')
        );
});
