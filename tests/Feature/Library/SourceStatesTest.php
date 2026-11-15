<?php

use App\Actions\Media\ResolveSourceManga;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Wikidata\WikidataClient;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Wikidata says The Matrix (Q83495) is based on one literary work carrying an
 * ISBN; the book provider resolves that ISBN to The Hobbit fixture (2459845).
 */
function fakeSourceBookBridge(): void
{
    test()->mock(WikidataClient::class, function ($mock): void {
        $mock->shouldReceive('select')->andReturnUsing(fn (string $sparql): array => str_contains($sparql, 'P212')
            ? [['work' => ['value' => 'http://www.wikidata.org/entity/Q74287'], 'isbn' => ['value' => '978-0-261-10221-7']]]
            : [[
                'work' => ['value' => 'http://www.wikidata.org/entity/Q74287'],
                'workLabel' => ['value' => 'The Hobbit'],
                'enLabel' => ['value' => 'The Hobbit'],
                'authorLabel' => ['value' => 'J. R. R. Tolkien'],
            ]]);
    });
}

test('the source books arrive with the viewer\'s state for their cards', function () {
    enableBooksModule();
    fakeHardcover(['ByIsbn' => 'by_isbn', 'Details' => 'book']);
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);
    fakeSourceBookBridge();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.rating.update', ['type' => 'book', 'ref' => '2459845']), ['rating' => 8])
        ->assertSuccessful();

    $this->actingAs($user)
        ->get('/movie/603')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/MovieDetails')
            // The render cannot know the books yet, so their state is absent…
            ->missing('userStates.book:2459845')
            ->missing('deferredUserStates')
            // …and lands with them in the follow-up request.
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('sourceBooks.0.ref', '2459845')
                ->where('deferredUserStates.book:2459845.rating', 8)
            )
        );
});

test('the manga an anime is based on arrives through the id resolver, from the manga provider', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga/2*' => Http::response(jsonFixture('mal/details'))]);
    test()->mock(WikidataClient::class, function ($mock): void {
        $mock->shouldReceive('select')->andReturn([['mal' => ['value' => '2']], ['mal' => ['value' => '2']], ['mal' => ['value' => 'not-an-id']]]);
    });

    $anime = new MediaDetails(summary: new MediaSummary(type: 'show', ref: '1429', provider: 'tmdb', title: 'Berserk'), wikidataId: 'Q1');

    $manga = app(ResolveSourceManga::class)->execute($anime, 'en');

    expect($manga)->toHaveCount(1)
        ->and($manga[0]->type)->toBe('manga')
        ->and($manga[0]->ref)->toBe('2');
});
