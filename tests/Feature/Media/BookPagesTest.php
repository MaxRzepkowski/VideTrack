<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('the book route is 404 when the books module is not configured', function () {
    $this->actingAs(User::factory()->create())
        ->get('/book/2459845')
        ->assertNotFound();
});

test('the book details page renders with pages, authors and the cover color', function () {
    enableBooksModule();
    fakeHardcover(['Details' => 'book']);

    $this->actingAs(User::factory()->create())
        ->get('/book/2459845')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/BookDetails')
            ->where('book.title', 'The Hobbit, or There and Back Again')
            ->where('book.type', 'book')
            ->where('book.extra.pages', 320)
            ->where('book.extra.coverColor', '#c58f27')
            ->where('book.extra.authors.0', 'J.R.R. Tolkien')
        );
});

test("a book's similar books arrive after its page, with the viewer's state for their cards", function () {
    enableBooksModule();
    fakeHardcover(['Details' => 'book', 'BooksByIds' => 'books']);
    $user = User::factory()->create();
    $url = route('title.details', ['type' => 'book', 'ref' => '2459845']);

    $this->actingAs($user)->get($url)->assertInertia(fn (Assert $page) => $page->missing('similar')->missing('deferredUserStates'));
    Http::assertNotSent(fn (Request $request): bool => str_contains((string) data_get($request->data(), 'query'), 'query BooksByIds'));

    $answer = deferredProp($url, 'media/BookDetails', 'similar,deferredUserStates')
        ->assertJsonPath('props.similar.0.ref', '312460')
        ->assertJsonCount(1, 'props.similar');

    expect(array_keys((array) $answer->json('props.deferredUserStates')))->toBe(['book:312460']);
});

test('a book page whose similar books cannot be fetched shows none', function () {
    enableBooksModule();
    Http::fake(['api.hardcover.app/*' => fn (Request $request) => str_contains((string) data_get($request->data(), 'query'), 'query BooksByIds')
        ? Http::response(['errors' => [['message' => 'Temporarily unavailable']]])
        : Http::response(jsonFixture('hardcover/book'))]);

    $user = User::factory()->create();
    $url = route('title.details', ['type' => 'book', 'ref' => '2459845']);

    $this->actingAs($user)->get($url)->assertOk();
    deferredProp($url, 'media/BookDetails', 'similar')
        ->assertOk()
        ->assertJsonPath('props.similar', []);
});

test("a book's page carries a banner for its series, counted in books", function () {
    enableBooksModule();
    fakeHardcover(['Details' => 'book', 'Series' => 'series']);

    $this->actingAs(User::factory()->create())
        ->get(route('title.details', ['type' => 'book', 'ref' => '2459845']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/BookDetails')
            ->where('collection.provider', 'hardcover')
            ->where('collection.ref', '127396')
            ->where('collection.name', 'Middle Earth')
            ->where('collection.partCount', 4)
            ->where('collection.partType', 'book')
        );
});

test('search offers one scope per provider plus a people scope, defaulting to the first', function () {
    enableGamesModule();
    enableBooksModule();
    Http::fake([
        '*/search/multi*' => Http::response(jsonFixture('tmdb/search')),
        'api.igdb.com/*' => Http::response(jsonFixture('igdb/search')),
    ]);
    fakeHardcover(['Search' => 'search']);

    $this->actingAs(User::factory()->create())
        ->get('/search?q=matrix')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/Search')
            // No "all" scope: the default targets the first provider only.
            ->where('scope', 'tmdb')
            ->where('scopes', [
                ['key' => 'tmdb', 'types' => ['show', 'movie']],
                ['key' => 'igdb', 'types' => ['game']],
                ['key' => 'hardcover', 'types' => ['book']],
                ['key' => 'person', 'types' => ['person']],
                ['key' => 'collection', 'types' => ['collection']],
                ['key' => 'company', 'types' => ['company']],
            ])
            // Exact TMDB refs prove the default scope ran without merging
            // results from any of the other configured providers.
            ->where('results', fn ($results) => collect($results)->pluck('ref')->all() === ['603', '1399', '999'])
        );
});

test('a selected search scope queries only its provider', function () {
    enableGamesModule();
    enableBooksModule();
    Http::fake([
        '*/search/multi*' => Http::response(jsonFixture('tmdb/search')),
        'api.igdb.com/*' => Http::response(jsonFixture('igdb/search')),
    ]);
    fakeHardcover(['Search' => 'search']);

    $this->actingAs(User::factory()->create())
        ->get('/search?q=hobbit&scope=hardcover')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'hardcover')
            ->where('results', fn ($results) => collect($results)->pluck('ref')->all() === ['2459845', '312460', '999999'])
        );

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'search/multi'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.igdb.com'));
});

test('an unknown scope falls back to the first provider', function () {
    enableBooksModule();
    Http::fake(['*/search/multi*' => Http::response(jsonFixture('tmdb/search'))]);
    fakeHardcover(['Search' => 'search']);

    $this->actingAs(User::factory()->create())
        ->get('/search?q=matrix&scope=bogus')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'tmdb')
            ->where('results', fn ($results) => collect($results)->pluck('ref')->all() === ['603', '1399', '999'])
        );
});

test('discover interleaves book sections with the other providers', function () {
    enableBooksModule();
    Http::fake(['api.themoviedb.org/*' => Http::response(['results' => []])]);
    fakeHardcover(['Trending' => 'trending', 'BooksByIds' => 'books', 'Popular' => 'books', 'Upcoming' => 'books']);

    $this->actingAs(User::factory()->create())
        ->get('/discover')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/Discover')
            ->where('availableTypes', fn ($types) => collect($types)->contains('book'))
        );
});
