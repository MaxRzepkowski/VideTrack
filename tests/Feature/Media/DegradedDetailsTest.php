<?php

use App\Catalog\Creators\PersonRecords;
use App\Catalog\TitleStore;
use App\Models\Company;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Flips a faked provider from healthy to dead mid-test. Http::fake() merges
 * its stubs instead of replacing them, so a second call could never take the
 * provider down — the switch has to live in the stub itself.
 */
function providerOutage(callable $healthy, int $status = 500): Closure
{
    $down = false;

    Http::fake(function ($request) use (&$down, $healthy, $status) {
        if ($down) {
            return Http::response([], $status);
        }

        return $healthy($request);
    });

    return function () use (&$down): void {
        $down = true;

        // The provider caches its own responses; a cached page would never
        // reach the failing transport.
        Cache::flush();
    };
}

test('a movie in the library survives the provider going down', function () {
    $outage = providerOutage(fn ($request) => str_contains($request->url(), '/movie/603')
        ? Http::response(jsonFixture('tmdb/movie'))
        : Http::response([]));

    app(TitleStore::class)->persist('movie', '603', 'en');

    $outage();

    $this->actingAs(User::factory()->create())
        ->get('/movie/603')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/MovieDetails')
            ->where('fromLibrary', true)
            ->where('movie.title', 'The Matrix')
            ->where('movie.overview', fn (?string $overview): bool => $overview !== null && $overview !== '')
            // The stored cast is the reason the page is worth serving at all.
            ->where('movie.cast.0.name', 'Keanu Reeves')
            // The bridge to the book it adapts survives with the row.
            ->where('movie.extra.wikidataId', 'Q83495')
            // Every section that needs the provider is simply absent.
            ->where('watchProviders', null)
            ->where('collection', null)
            ->etc());
});

test('a provider refusing the key serves the stored movie the same way as one that is down', function () {
    $outage = providerOutage(fn ($request) => str_contains($request->url(), '/movie/603')
        ? Http::response(jsonFixture('tmdb/movie'))
        : Http::response([]), status: 401);

    app(TitleStore::class)->persist('movie', '603', 'en');

    $outage();

    $this->actingAs(User::factory()->create())
        ->get('/movie/603')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/MovieDetails')
            ->where('fromLibrary', true)
            ->where('movie.title', 'The Matrix')
            ->etc());
});

test('a degraded movie keeps its release rows and classifications', function () {
    $outage = providerOutage(fn ($request) => match (true) {
        str_contains($request->url(), '/movie/603') => Http::response(jsonFixture('tmdb/movie')),
        str_contains($request->url(), 'certification/movie/list') => Http::response(jsonFixture('tmdb/certifications_movie')),
        default => Http::response([]),
    });

    app(TitleStore::class)->persist('movie', '603', 'en');

    $outage();

    $this->actingAs(User::factory()->create())
        ->get('/movie/603')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Typed fields, rehydrated out of the stored metadata — the same
            // shape the live provider serves, so the page needs no branch.
            ->where('movie.releases.0.region', 'US')
            ->where('movie.releases.0.kind', 'limited')
            ->where('movie.releases.0.note', 'Westwood premiere')
            ->where('movie.certification', 'R')
            ->where('movie.certifications.0.code', 'R')
            ->where('movie.certifications.0.meaning', 'Under 17 requires an accompanying parent or adult guardian.')
            ->where('movie.keywords.0.name', 'artificial intelligence')
            ->where('movie.tagline', fn (?string $tagline): bool => $tagline !== null)
            ->etc());
});

test('a movie nobody tracks still fails when the provider does', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $this->actingAs(User::factory()->create())
        ->get('/movie/603')
        ->assertStatus(503);
});

test('a healthy provider page says nothing about the library', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    $this->actingAs(User::factory()->create())
        ->get('/movie/603')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('fromLibrary', false)->etc());
});

test('a show keeps its season list when the provider goes down', function () {
    $outage = providerOutage(function ($request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/tv/1399/season/0') => Http::response(jsonFixture('tmdb/season0')),
            str_contains($url, '/tv/1399/season/1') => Http::response(jsonFixture('tmdb/season')),
            str_contains($url, '/tv/1399/season/2') => Http::response(jsonFixture('tmdb/season2')),
            str_contains($url, '/tv/1399') => tmdbShowAnswer()($request),
            default => Http::response([]),
        };
    });

    app(TitleStore::class)->persist('show', '1399', 'en');

    $outage();

    $this->actingAs(User::factory()->create())
        ->get('/show/1399')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/ShowDetails')
            ->where('fromLibrary', true)
            ->where('show.title', 'Game of Thrones')
            ->has('show.children', 3)
            ->etc());
});

test('a season and an episode degrade to their stored rows', function () {
    $outage = providerOutage(function ($request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/tv/1399/season/0') => Http::response(jsonFixture('tmdb/season0')),
            str_contains($url, '/tv/1399/season/1') => Http::response(jsonFixture('tmdb/season')),
            str_contains($url, '/tv/1399/season/2') => Http::response(jsonFixture('tmdb/season2')),
            str_contains($url, '/tv/1399') => tmdbShowAnswer()($request),
            default => Http::response([]),
        };
    });

    app(TitleStore::class)->persist('show', '1399', 'en');

    $outage();

    $episode = MediaItem::query()->where(['type' => 'episode', 'provider_ref' => '1399:1:1'])->sole();

    expect($episode->metadata)->not->toHaveKeys(['seasonNumber', 'runtime', 'overview'])
        ->and($episode->runtime)->toBe(62);

    // What the provider marked the episode as, kept on its row.
    $episode->forceFill(['metadata' => [...$episode->metadata ?? [], 'episodeType' => 'finale']])->save();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/show/1399/season/1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/SeasonDetails')
            ->where('fromLibrary', true)
            ->where('watchProviders', null)
            // The episode cards read the same fields as with TMDB up,
            // from the columns rather than from copies in `metadata`.
            ->where('season.children.0.extra.seasonNumber', 1)
            ->where('season.children.0.extra.runtime', 62)
            ->where('season.children.0.extra.episodeType', 'finale')
            ->where('season.children.0.extra.overview', fn (?string $overview): bool => $overview !== null && $overview !== '')
            ->etc());

    $this->actingAs($user)
        ->get('/show/1399/season/1/episode/1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/EpisodeDetails')
            ->where('fromLibrary', true)
            ->where('episode.extra.episodeType', 'finale')
            ->etc());
});

test('a followed person keeps their biography through an outage', function () {
    $outage = providerOutage(fn ($request) => str_contains($request->url(), '/person/6384')
        ? Http::response(jsonFixture('tmdb/person'))
        : Http::response([]));

    app(PersonRecords::class)->ensure('tmdb', '6384', 'en');

    $outage();

    $this->actingAs(User::factory()->create())
        ->get('/person/tmdb/6384')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/CreatorPage')->where('kind', 'person')
            ->where('fromLibrary', true)
            ->where('creator.name', 'Keanu Reeves')
            ->where('creator.biography', 'Keanu Charles Reeves is a Canadian actor.')
            ->where('creator.birthday', '1964-09-02')
            ->where('creator.placeOfBirth', 'Beirut, Lebanon')
            ->etc());
});

test('a person nobody tracks still fails when the provider does', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $this->actingAs(User::factory()->create())
        ->get('/person/tmdb/6384')
        ->assertStatus(503);
});

test('a book in the library survives its provider going down, like every other type', function () {
    enableBooksModule();
    $outage = providerOutage(fn ($request) => Http::response(jsonFixture('hardcover/book')));

    app(TitleStore::class)->persist('book', '2459845', 'en');

    $outage();

    $this->actingAs(User::factory()->create())
        ->get('/book/2459845')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/BookDetails')
            ->where('fromLibrary', true)
            ->where('removedAtSource', false)
            ->where('book.title', 'The Hobbit, or There and Back Again')
            ->where('book.extra.authors', ['J.R.R. Tolkien'])
            ->etc());
});

test('a book nobody tracks still fails when its provider does', function () {
    enableBooksModule();
    $outage = providerOutage(fn ($request) => Http::response([]));
    $outage();

    $this->actingAs(User::factory()->create())
        ->get('/book/2459845')
        ->assertServiceUnavailable();
});

test('a stored studio keeps its description through an outage', function () {
    Http::fake(['*' => Http::response([], 500)]);
    Company::factory()->create([
        'provider_ref' => '420',
        'name' => 'Marvel Studios',
        'metadata' => ['description' => 'An American film studio.', 'country' => 'US'],
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/company/tmdb/420')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/CreatorPage')->where('kind', 'company')
            ->where('fromLibrary', true)
            ->where('creator.name', 'Marvel Studios')
            ->where('creator.description', 'An American film studio.')
            ->where('creator.country', 'US')
            ->etc());
});
