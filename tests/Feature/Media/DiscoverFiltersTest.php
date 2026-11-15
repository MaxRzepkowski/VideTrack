<?php

use App\Catalog\ProviderRegistry;
use App\Enums\PlayOutcome;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FilteredOnlyProvider;

/**
 * Discover's filters seen from the page: which filters reach the props, what an aged
 * link does, how a filtered grid pages, and what happens when a dictionary is
 * down. The provider-level parameter assertions live in the Tmdb/Igdb filter
 * tests — here the subject is the controller.
 */

/** @return array<string, Response> */
function discoverDictionaryFakes(): array
{
    return [
        '*/configuration/languages*' => Http::response([['iso_639_1' => 'en', 'english_name' => 'English']]),
        '*/configuration/countries*' => Http::response([['iso_3166_1' => 'US', 'english_name' => 'United States']]),
        '*/certification/movie/list*' => Http::response(jsonFixture('tmdb/certifications_movie')),
        '*/certification/tv/list*' => Http::response(jsonFixture('tmdb/certifications_tv')),
    ];
}

/** Every TMDB endpoint the discover page can touch, all healthy. */
function fakeDiscoverEndpoints(): void
{
    Http::fake([
        ...discoverDictionaryFakes(),
        '*/trending/*' => Http::response(['results' => []]),
        '*/tv/popular*' => Http::response(['results' => []]),
        '*/tv/on_the_air*' => Http::response(['results' => []]),
        '*/movie/popular*' => Http::response(['results' => []]),
        '*/genre/tv/list*' => Http::response(['genres' => [
            ['id' => 18, 'name' => 'Drama'],
            ['id' => 35, 'name' => 'Comedy'],
        ]]),
        '*/genre/movie/list*' => Http::response(['genres' => [['id' => 28, 'name' => 'Action']]]),
        '*/watch/providers/*' => Http::response(['results' => [
            ['provider_id' => 8, 'provider_name' => 'Netflix', 'display_priority' => 1],
        ]]),
        '*/discover/*' => Http::response([
            'page' => 1,
            'total_pages' => 1,
            'total_results' => 1,
            'results' => [
                ['id' => 300, 'name' => 'Drama Show', 'title' => 'Drama Show', 'poster_path' => '/d.jpg', 'first_air_date' => '2019-01-01', 'release_date' => '2019-01-01'],
            ],
        ]),
    ]);
}

/** The discover URL for one genre selection. */
function discoverGenreUrl(string $type = 'show', string $ref = '18'): string
{
    return route('discover', ['type' => $type, 'f' => ['genres' => [$ref]]]);
}

test('the filter definitions reach the page in the provider order', function () {
    fakeDiscoverEndpoints();

    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'show']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.0.key', 'genres')
            ->where('filters.0.kind', 'multi_select')
            // An ordered list, not a map — the browser must not re-sort ids.
            ->where('filters.0.options.0.value', '18')
            ->where('filters.0.options.1.value', '35')
            ->where('filters.1.key', 'exclude_genres')
            ->where('filters.2.key', 'year')
            ->where('filters.3.key', 'runtime')
            ->where('filters.8.key', 'status')
            ->where('filters.9.key', 'show_type')
            ->where('filters.11.key', 'watch_provider')
            ->where('filters.12.key', 'sort')
            ->where('filters.12.default', 'popularity.desc')
            ->where('filterCount', 0)
            ->where('filteredPagination', null)
            // Nothing filtered yet: the browse sections are the page.
            ->has('trending')
            ->has('popular'));
});

test('a provider without the filtered contract keeps its browse page with genres', function () {
    enableBooksModule();
    fakeHardcover(['Genres' => 'genres']);

    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'book']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', null)
            ->where('filterCount', null)
            ->where('filteredItems', [])
            ->where('filteredPagination', null)
            ->has('genres', 2));
});

test('a provider without the contract answers 404 for a genre it does not have', function () {
    enableBooksModule();
    fakeHardcover(['Genres' => 'genres']);

    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'book', 'genre' => 'No Such Genre']))
        ->assertNotFound();
});

test('a filtered url renders the grid, its count and the browse sections gone', function () {
    fakeDiscoverEndpoints();

    $this->actingAs(User::factory()->create())
        ->get(discoverGenreUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeFilters.genres', ['18'])
            ->where('filterCount', 1)
            ->where('filteredItems.0.title', 'Drama Show')
            ->where('filteredPagination.total', 1)
            ->where('trending', [])
            ->where('popular', [])
            ->where('upcoming', [])
            ->where('failed', false));
});

test('an aged link is shrugged off rather than turned into a 404', function () {
    fakeDiscoverEndpoints();

    $this->actingAs(User::factory()->create())
        // An unknown key, a genre the provider dropped, and a bad year.
        ->get(route('discover', ['type' => 'show', 'f' => [
            'runtime' => '90',
            'genres' => ['18', '9999'],
            'year_from' => 'soon',
        ]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeFilters.genres', ['18'])
            ->where('filterCount', 1)
            ->where('failed', false));

    // Neither the unknown key nor the dropped genre reached TMDB.
    Http::assertSent(fn ($request) => ! str_contains($request->url(), '/discover/')
        || (str_contains(urldecode($request->url()), 'with_genres=18')
            && ! str_contains($request->url(), '9999')));
});

test('a filter whose dictionary is down empties the grid instead of widening it', function () {
    Http::fake([
        ...discoverDictionaryFakes(),
        '*/genre/tv/list*' => Http::response(['genres' => [['id' => 18, 'name' => 'Drama']]]),
        '*/watch/providers/*' => Http::response([], 500),
        '*/discover/*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 9, 'results' => [
            ['id' => 300, 'name' => 'Drama Show', 'poster_path' => '/d.jpg', 'first_air_date' => '2019-01-01'],
        ]]),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'show', 'f' => ['watch_provider' => '8']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeFilters.watch_provider', '8')
            ->where('filteredItems', [])
            ->where('failed', true));

    // A URL that names one service must never be answered with the catalogue.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/discover/'));
});

test('an unavailable filter nobody selected leaves the page working', function () {
    Http::fake([
        ...discoverDictionaryFakes(),
        '*/trending/*' => Http::response(['results' => []]),
        '*/tv/popular*' => Http::response(['results' => []]),
        '*/tv/on_the_air*' => Http::response(['results' => []]),
        '*/genre/tv/list*' => Http::response(['genres' => [['id' => 18, 'name' => 'Drama']]]),
        '*/watch/providers/*' => Http::response([], 500),
        '*/discover/*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 1, 'results' => [
            ['id' => 300, 'name' => 'Drama Show', 'poster_path' => '/d.jpg', 'first_air_date' => '2019-01-01'],
        ]]),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(discoverGenreUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.11.key', 'watch_provider')
            ->where('filters.11.unavailable', true)
            ->where('filteredItems.0.title', 'Drama Show'));
});

test('both genre spellings at once: the filter wins and the by-name one is cut', function () {
    fakeDiscoverEndpoints();

    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'show', 'genre' => 'Comedy', 'f' => ['genres' => ['18']]]))
        ->assertRedirect(discoverGenreUrl());
});

test('a genre name written in another locale still resolves', function () {
    fakeDiscoverEndpoints();
    app(ProviderRegistry::class)->register('tmdb', FilteredOnlyProvider::class);

    // Chips built from persisted metadata carry whichever locale wrote them.
    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'show', 'genre' => 'Dramat']))
        ->assertRedirect(discoverGenreUrl());
});

test('a page past the last one is rewritten rather than shown empty', function () {
    Http::fake([
        ...discoverDictionaryFakes(),
        '*/genre/tv/list*' => Http::response(['genres' => [['id' => 18, 'name' => 'Drama']]]),
        '*/watch/providers/*' => Http::response(['results' => []]),
        '*/discover/*' => Http::response(['page' => 3, 'total_pages' => 3, 'total_results' => 55, 'results' => [
            ['id' => 300, 'name' => 'Last Page Show', 'poster_path' => '/d.jpg', 'first_air_date' => '2019-01-01'],
        ]]),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('discover', ['type' => 'show', 'f' => ['genres' => ['18']], 'page' => 501]))
        ->assertRedirect(route('discover', ['type' => 'show', 'f' => ['genres' => ['18']], 'page' => 3]));

    // The rewritten address really carries the last page's content.
    $this->actingAs($user)
        ->get(route('discover', ['type' => 'show', 'f' => ['genres' => ['18']], 'page' => 3]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filteredItems.0.title', 'Last Page Show')
            ->where('filteredPagination.currentPage', 3)
            ->where('filteredPagination.totalPages', 3));
});

test('a nonsense page number is simply page one, with no redirect', function () {
    fakeDiscoverEndpoints();

    foreach (['abc', '0', '-3'] as $page) {
        $this->actingAs(User::factory()->create())
            ->get(route('discover', ['type' => 'show', 'f' => ['genres' => ['18']], 'page' => $page]))
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia->where('filteredPagination.currentPage', 1));
    }
});

test('a combination that matches nothing is an empty grid, not a failure', function () {
    Http::fake([
        ...discoverDictionaryFakes(),
        '*/genre/tv/list*' => Http::response(['genres' => [['id' => 18, 'name' => 'Drama']]]),
        '*/watch/providers/*' => Http::response(['results' => []]),
        '*/discover/*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'results' => []]),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(discoverGenreUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filteredItems', [])
            ->where('filteredPagination.total', 0)
            ->where('failed', false));
});

test('the streaming region follows the user preference, not the locale alone', function () {
    fakeDiscoverEndpoints();

    $user = User::factory()->create(['preferences' => ['region' => 'de']]);

    $this->actingAs($user)
        ->get(route('discover', ['type' => 'show', 'f' => ['watch_provider' => '8']]))
        ->assertOk();

    // Both the dictionary and the query are scoped to the user's region.
    Http::assertSent(fn ($request) => ! str_contains($request->url(), '/watch/providers/')
        || str_contains($request->url(), 'watch_region=DE'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/discover/')
        && str_contains($request->url(), 'watch_region=DE'));
});

/** A watched movie the filtered grid will also return (provider ref 300). */
function watchedFilteredMovie(User $user): void
{
    $movie = MediaItem::factory()->movie()->create(['provider' => 'tmdb', 'provider_ref' => '300']);

    Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $movie->id,
        'outcome' => PlayOutcome::Completed,
        'watched_at' => now(),
    ]);
    projectLibrary($user);
}

test('the filtered cards carry the library state', function () {
    fakeDiscoverEndpoints();

    $user = User::factory()->create();
    watchedFilteredMovie($user);

    $this->actingAs($user)
        ->get(discoverGenreUrl('movie', '28'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filteredItems.0.ref', '300')
            ->where('userStates.movie:300.watched', true));
});

test('a partial reload after a filter change carries everything that differs', function () {
    fakeDiscoverEndpoints();

    $user = User::factory()->create();
    watchedFilteredMovie($user);

    // A partial reload always follows a full visit.
    $this->actingAs($user)->get(discoverGenreUrl('movie', '28'))->assertOk();

    $this->actingAs($user)
        ->get(discoverGenreUrl('movie', '28'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'media/Discover',
            'X-Inertia-Partial-Data' => 'filters,filteredItems,filteredPagination,activeFilters,filterCount,failed,userStates',
        ])
        ->assertOk()
        ->assertJsonPath('props.filters.0.key', 'genres')
        ->assertJsonPath('props.filteredItems.0.title', 'Drama Show')
        ->assertJsonPath('props.filteredPagination.total', 1)
        ->assertJsonPath('props.activeFilters.genres', ['28'])
        ->assertJsonPath('props.filterCount', 1)
        ->assertJsonPath('props.failed', false)
        // userStates is a plain closure prop: without it in `only` the cards
        // would come back stateless.
        ->assertJsonPath('props.userStates.movie:300.watched', true)
        ->assertJsonMissingPath('props.trending');
});

test('clearing the last filter brings the browse sections back', function () {
    fakeDiscoverEndpoints();

    // Dropping the last filter is a full visit, not a partial one — the
    // sections only exist on a response that carries them all.
    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'show']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filterCount', 0)
            ->where('filteredItems', [])
            ->where('activeFilters', [])
            ->has('trending')
            ->has('popular')
            ->has('upcoming'));
});

test('a provider with only the filtered contract is a first-class citizen', function () {
    app(ProviderRegistry::class)->register('tmdb', FilteredOnlyProvider::class);

    $user = User::factory()->create();

    // The type select and the header mega-menu both offer the type…
    $this->actingAs($user)
        ->get(route('discover', ['type' => 'show']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('availableTypes', fn (Collection $types): bool => $types->contains('show'))
            ->where('discoverTypes', fn (Collection $types): bool => $types->contains('show'))
            ->where('filters.0.key', 'genres')
            // …with no browse sections behind it, because it has none.
            ->where('trending', [])
            ->where('popular', []));

    // …filtering works…
    $this->actingAs($user)
        ->get(discoverGenreUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filteredItems.0.title', 'Filtered Only Title')
            ->where('filterCount', 1));

    // …and so does the by-name link, without SupportsDiscovery anywhere.
    $this->actingAs($user)
        ->get(route('discover', ['type' => 'show', 'genre' => 'Drama']))
        ->assertRedirect(discoverGenreUrl());
});
