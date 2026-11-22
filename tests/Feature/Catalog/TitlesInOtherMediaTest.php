<?php

use App\Actions\Media\RefreshTitleRelations;
use App\Catalog\MediaKind;
use App\Catalog\Relations\BookByTitle;
use App\Catalog\Relations\RelationStore;
use App\Catalog\Relations\TitleRelation;
use App\Catalog\Relations\TitleTie;
use App\Catalog\Wikidata\RelatedWorks;
use App\Catalog\Wikidata\TitleItems;
use App\Models\MediaItem;
use App\Models\RelatedTitle;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/**
 * The Hobbit as Hardcover answers for the cards of many books at once.
 *
 * @return array<string, mixed>
 */
function hardcoverBookCards(): array
{
    return ['data' => ['books' => [jsonFixture('hardcover/book')['data']['books_by_pk']]]];
}

/**
 * One row of Wikidata's answer across media: the title asked about, how the
 * other work stands to it and the other work's ids, as Wikidata writes them.
 *
 * @param  array<string, string>  $bindings  variable => value
 * @return array<string, array{type: string, value: string}>
 */
function acrossMediaRow(string $subject, TitleRelation $relation, string $other, array $bindings = []): array
{
    return [
        's' => ['type' => 'uri', 'value' => "http://www.wikidata.org/entity/{$subject}"],
        'rel' => ['type' => 'literal', 'value' => $relation->value],
        'o' => ['type' => 'uri', 'value' => "http://www.wikidata.org/entity/{$other}"],
        ...array_map(fn (string $value): array => ['type' => 'literal', 'value' => $value], $bindings),
    ];
}

/**
 * Wikidata answering the question that finds our titles' items with the
 * given ones, the question across media with the given rows and the one
 * about books' editions with the given ids; every other request answered by
 * the given callback.
 *
 * @param  list<array{string, string, string}>  $items  the id case, the value and the item
 * @param  list<array<string, mixed>>  $rows
 * @param  Closure(Request): mixed  $otherwise
 * @param  list<array{string, string, string}>  $editions  the book's QID, the id case and the value
 */
function fakeWikidataAcrossMedia(array $items, array $rows, ?Closure $otherwise = null, array $editions = []): void
{
    Http::fake(function (Request $request) use ($items, $rows, $otherwise, $editions) {
        if (! str_contains($request->url(), '/sparql')) {
            return $otherwise === null ? Http::response([], 404) : $otherwise($request);
        }

        $query = (string) ($request->data()['query'] ?? '');

        return Http::response(['results' => ['bindings' => match (true) {
            str_contains($query, 'SELECT ?id ?value ?item') => array_map(fn (array $item): array => [
                'id' => ['type' => 'literal', 'value' => $item[0]],
                'value' => ['type' => 'literal', 'value' => $item[1]],
                'item' => ['type' => 'uri', 'value' => "http://www.wikidata.org/entity/{$item[2]}"],
            ], $items),
            str_contains($query, 'wdt:P4969') => $rows,
            str_contains($query, 'SELECT ?o ?goodreads ?isbn') => array_map(fn (array $edition): array => [
                'o' => ['type' => 'uri', 'value' => "http://www.wikidata.org/entity/{$edition[0]}"],
                $edition[1] => ['type' => 'literal', 'value' => $edition[2]],
            ], $editions),
            default => [],
        }]]);
    });
}

test('one question across media reads each catalogue\'s id, and a book known by none by its title', function () {
    fakeWikidataAcrossMedia([], [
        acrossMediaRow('Q482494', TitleRelation::Adaptation, 'Q55633945', ['manga' => '17517']),
        acrossMediaRow('Q482494', TitleRelation::Adaptation, 'Q1', ['game' => '2262', 'show' => '42509']),
        // Comic Vine numbers its characters and issues apart from its volumes.
        acrossMediaRow('Q482494', TitleRelation::SameSource, 'Q2', ['comic' => '4005-1699']),
        acrossMediaRow('Q482494', TitleRelation::SameSource, 'Q3', ['comic' => '4050-18166']),
        acrossMediaRow('Q482494', TitleRelation::BasedOn, 'Q4', ['literary' => 'true', 'label' => 'The Hobbit', 'authors' => 'J. R. R. Tolkien|Christopher Tolkien']),
        acrossMediaRow('Q482494', TitleRelation::BasedOn, 'Q5', ['literary' => 'true', 'label' => 'Farmer Giles of Ham']),
        // A tie of the other question is not this one's to read.
        acrossMediaRow('Q482494', TitleRelation::SpinOff, 'Q6', ['show' => '1']),
    ], editions: [
        ['Q4', 'goodreads', '5907'],
        // An English edition's ISBN wins over another one's.
        ['Q4', 'isbn', '978-88-452-9040-9'],
        ['Q4', 'isbn', '978-0-261-10221-7'],
    ]);

    $ties = app(RelatedWorks::class)->acrossMedia(['Q482494', 'not-an-item; DROP'])['Q482494'];

    expect(array_map(fn ($tie) => [$tie->relation, $tie->work->kind, $tie->work->externalIds, $tie->work->title, $tie->work->authors], $ties))->toBe([
        [TitleRelation::Adaptation, MediaKind::Manga, ['mal' => '17517'], null, []],
        // A screen work wins over the other ids a row carries.
        [TitleRelation::Adaptation, MediaKind::Show, ['tmdb' => '42509'], null, []],
        [TitleRelation::SameSource, MediaKind::Comic, ['comicvine' => '18166'], null, []],
        [TitleRelation::BasedOn, MediaKind::Book, ['goodreads' => '5907', 'isbn' => '9780261102217'], 'The Hobbit', ['J. R. R. Tolkien', 'Christopher Tolkien']],
        [TitleRelation::BasedOn, MediaKind::Book, [], 'Farmer Giles of Ham', []],
    ]);

    Http::assertSent(function (Request $request) {
        $query = $request->data()['query'];

        return str_contains($query, 'VALUES ?s { wd:Q482494 }')
            && str_contains($query, '?s wdt:P144 ?o') && str_contains($query, '?o wdt:P4969 ?s')
            && str_contains($query, '?s wdt:P4969 ?o') && str_contains($query, '?o wdt:P144 ?s')
            // Fictional character, fictional universe and media franchise are no shared source.
            && str_contains($query, 'wd:Q95074 wd:Q559618 wd:Q196600')
            && str_contains($query, 'p:P5794/pq:P9043')
            && ! str_contains($query, 'DROP');
    });
});

test('a title is found on Wikidata by an id of ours, unless its provider already names its item', function () {
    enableMangaModule();
    enableGamesModule();
    enableBooksModule();
    enableComicsModule();
    $show = MediaItem::factory()->show()->create(['metadata' => ['wikidataId' => 'Q1079']]);
    $manga = MediaItem::factory()->manga()->create(['provider_ref' => '17517']);
    $game = MediaItem::factory()->game()->create(['provider_ref' => '2262']);
    $comic = MediaItem::factory()->comic()->create(['provider_ref' => '18166']);
    $book = MediaItem::factory()->book()->create(['provider_ref' => '1', 'metadata' => ['goodreadsId' => '50202953']]);
    fakeWikidataAcrossMedia([
        ['manga', '17517', 'Q55633945'],
        ['game', '2262', 'Q1456987'],
        ['game', '2262', 'Q108823077'],
        ['comic', '4050-18166', 'Q7'],
        ['goodreads', '50202953', 'Q99434216'],
    ], []);

    $items = app(TitleItems::class)->of([$show, $manga, $game, $comic, $book]);

    expect($items)->toBe([
        $show->id => ['Q1079'],
        $manga->id => ['Q55633945'],
        $game->id => ['Q1456987', 'Q108823077'],
        $comic->id => ['Q7'],
        $book->id => ['Q99434216'],
    ])->and(app(TitleItems::class)->findableTypes())->toEqualCanonicalizing(['movie', 'show', 'manga', 'game', 'comic', 'book']);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $query = $request->data()['query'];

        return str_contains($query, '"17517"') && str_contains($query, 'wdt:P4087')
            && str_contains($query, '"4050-18166"')
            // A Goodreads id names an edition; the item asked about is its work.
            && str_contains($query, 'wdt:P2969') && str_contains($query, 'wdt:P629')
            && ! str_contains($query, 'Q1079');
    });
});

test('a run keeps a title\'s works in other media with how widely known each is, and not those of its own type', function () {
    enableMangaModule();
    $manga = MediaItem::factory()->manga()->create(['provider_ref' => '17517', 'title' => 'Steins;Gate']);
    $asked = [];
    fakeWikidataAcrossMedia(
        [['manga', '17517', 'Q55633945']],
        [
            acrossMediaRow('Q55633945', TitleRelation::SameSource, 'Q20590069', ['show' => '42509']),
            acrossMediaRow('Q55633945', TitleRelation::SameSource, 'Q8', ['manga' => '99999']),
        ],
        function (Request $request) use (&$asked) {
            $asked[] = $request->url();

            return str_contains($request->url(), '/tv/42509')
                ? Http::response([...jsonFixture('tmdb/tv'), 'id' => 42509, 'name' => 'Steins;Gate', 'vote_count' => 1234])
                : Http::response([], 404);
        },
    );

    expect(app(RefreshTitleRelations::class)->execute(MediaItem::query()->whereKey($manga->id)->get(), 'en'))->toBe(1);

    expect(RelatedTitle::query()->where('media_item_id', $manga->id)->get(['relation', 'type', 'ref', 'votes'])->toArray())
        ->toBe([['relation' => 'same_source', 'type' => 'show', 'ref' => '42509', 'votes' => 1234]])
        // The other manga is not looked up at all.
        ->and(array_filter($asked, fn (string $url): bool => str_contains($url, 'myanimelist')))->toBe([]);
});

test('a book Wikidata knows by no id is found by its title and authors', function () {
    enableBooksModule();
    $film = MediaItem::factory()->movie()->create(['metadata' => ['wikidataId' => 'Q1']]);
    fakeWikidataAcrossMedia([], [
        acrossMediaRow('Q1', TitleRelation::BasedOn, 'Q74287', ['literary' => 'true', 'label' => 'The Hobbit', 'authors' => 'J. R. R. Tolkien']),
    ], function (Request $request) {
        $query = (string) data_get($request->data(), 'query');

        return match (true) {
            str_contains($query, 'query Search') => Http::response(jsonFixture('hardcover/search')),
            str_contains($query, 'query BookCards') => Http::response(hardcoverBookCards()),
            default => Http::response([], 404),
        };
    });

    app(RefreshTitleRelations::class)->execute(MediaItem::query()->whereKey($film->id)->get(), 'en');

    expect(RelatedTitle::query()->where('media_item_id', $film->id)->get(['relation', 'type', 'ref'])->toArray())
        ->toBe([['relation' => 'based_on', 'type' => 'book', 'ref' => '2459845']]);
});

test('the work a title is based on is always shown; what was made of it only when widely known or stored here', function () {
    config(['tracker.widely_known_votes.game' => 100]);
    $film = MediaItem::factory()->movie()->create(['metadata' => ['wikidataId' => 'Q1']]);
    $stored = MediaItem::factory()->game()->create(['provider_ref' => '300', 'title' => 'A Stored Game']);
    RelatedTitle::factory()->create(['media_item_id' => $film->id, 'relation' => TitleRelation::BasedOn, 'type' => 'book', 'provider' => 'hardcover', 'ref' => '10', 'title' => 'A Little-Read Novel', 'votes' => 3]);
    RelatedTitle::factory()->create(['media_item_id' => $film->id, 'relation' => TitleRelation::Adaptation, 'type' => 'game', 'provider' => 'igdb', 'ref' => '100', 'title' => 'A Tie-In Game', 'votes' => 99]);
    RelatedTitle::factory()->create(['media_item_id' => $film->id, 'relation' => TitleRelation::Adaptation, 'type' => 'game', 'provider' => 'igdb', 'ref' => '200', 'title' => 'The Game', 'votes' => 100]);
    RelatedTitle::factory()->create(['media_item_id' => $film->id, 'relation' => TitleRelation::SameSource, 'type' => 'game', 'provider' => 'igdb', 'ref' => '300', 'title' => 'A Stored Game', 'votes' => null]);
    // Filed both ways round: it is the source.
    RelatedTitle::factory()->create(['media_item_id' => $film->id, 'relation' => TitleRelation::Adaptation, 'type' => 'book', 'provider' => 'hardcover', 'ref' => '10', 'title' => 'A Little-Read Novel', 'votes' => 3]);

    expect(array_map(fn (TitleTie $tie) => [$tie->relation, $tie->media->title], app(RelationStore::class)->of($film)))->toBe([
        [TitleRelation::BasedOn, 'A Little-Read Novel'],
        [TitleRelation::Adaptation, 'The Game'],
        [TitleRelation::SameSource, $stored->title],
    ]);
});

test('a work in another medium is read from both ends: the novel names the film made of it', function () {
    enableBooksModule();
    $novel = MediaItem::factory()->book()->create(['provider_ref' => '10', 'title' => 'The Novel']);
    $film = MediaItem::factory()->movie()->create(['title' => 'The Film']);
    RelatedTitle::factory()->create(['media_item_id' => $film->id, 'relation' => TitleRelation::BasedOn, 'type' => 'book', 'provider' => 'hardcover', 'ref' => '10', 'title' => 'The Novel']);

    expect(array_map(fn (TitleTie $tie) => [$tie->relation, $tie->media->title], app(RelationStore::class)->of($novel)))
        ->toBe([[TitleRelation::Adaptation, 'The Film']]);
});

test('a title\'s page lists its works in other media apart from its universe, with their states', function () {
    enableMangaModule();
    $user = User::factory()->create();
    $manga = MediaItem::factory()->manga()->create(['provider_ref' => '2', 'title' => 'Berserk']);
    RelatedTitle::factory()->create(['media_item_id' => $manga->id, 'relation' => TitleRelation::Adaptation, 'type' => 'show', 'ref' => '35935', 'title' => 'Berserk']);
    Http::fake(['api.myanimelist.net/v2/manga/2*' => Http::response(jsonFixture('mal/details')), '*' => Http::response([], 404)]);

    $this->actingAs($user)
        ->get(route('title.details', ['type' => 'manga', 'ref' => '2']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('otherMedia', fn ($ties) => collect($ties)->map(fn ($tie) => [$tie['relation'], $tie['label'], $tie['media']['type'], $tie['media']['ref']])->all() === [['adaptation', 'Adaptation', 'show', '35935']])
            ->has('userStates.show:35935'));

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'wikidata'));
});

test('a book whose authors Wikidata does not know is taken only under its very title', function () {
    enableBooksModule();
    $film = MediaItem::factory()->movie()->create(['metadata' => ['wikidataId' => 'Q1']]);
    fakeWikidataAcrossMedia([], [
        acrossMediaRow('Q1', TitleRelation::SameSource, 'Q9', ['literary' => 'true', 'label' => 'Dune Messiah']),
    ], fn (Request $request) => match (true) {
        str_contains((string) data_get($request->data(), 'query'), 'query Search') => Http::response(jsonFixture('hardcover/search')),
        str_contains((string) data_get($request->data(), 'query'), 'query BookCards') => Http::response(hardcoverBookCards()),
        default => Http::response([], 404),
    });

    app(RefreshTitleRelations::class)->execute(MediaItem::query()->whereKey($film->id)->get(), 'en');

    // The search's best hits are other books ("Dune", "The Hobbit…"): none is it.
    expect(RelatedTitle::query()->where('media_item_id', $film->id)->count())->toBe(0);
});

test('the page of a title not stored here still shows the works that name it', function () {
    enableMangaModule();
    $show = MediaItem::factory()->show()->create(['title' => 'Sword Art Online']);
    RelatedTitle::factory()->create(['media_item_id' => $show->id, 'relation' => TitleRelation::BasedOn, 'type' => 'manga', 'provider' => 'mal', 'ref' => '21479', 'title' => 'Sword Art Online']);
    Http::fake(['api.myanimelist.net/v2/manga/21479*' => Http::response([...jsonFixture('mal/details'), 'id' => 21479]), '*' => Http::response([], 404)]);

    $this->actingAs(User::factory()->create())
        ->get(route('title.details', ['type' => 'manga', 'ref' => '21479']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('otherMedia', fn ($ties) => collect($ties)->map(fn ($tie) => [$tie['relation'], $tie['media']['type'], $tie['media']['title']])->all() === [['adaptation', 'show', 'Sword Art Online']]));

    expect(MediaItem::query()->where('type', 'manga')->exists())->toBeFalse();
});

test('the run asks about roots still at their provider, of a type Wikidata can find', function () {
    $show = MediaItem::factory()->show()->create();
    $gone = MediaItem::factory()->show()->create(['gone_at' => now()]);
    $season = MediaItem::factory()->season($show, 1)->create();
    // The books module is off: Wikidata has nothing to find a book by.
    $book = MediaItem::factory()->book()->create(['metadata' => ['goodreadsId' => '1']]);

    expect(array_map(app(TitleItems::class)->findable(...), [$show, $gone, $season, $book]))->toBe([true, false, false, false]);
});

test('a book of the work\'s title by someone else is asked for again with the author, and dropped if still not theirs', function (string $author, ?string $found) {
    enableBooksModule();
    $searched = [];
    Http::fake(['api.hardcover.app/*' => function (Request $request) use (&$searched) {
        $searched[] = (string) data_get($request->data(), 'variables.query');

        return Http::response(jsonFixture('hardcover/search'));
    }]);

    $book = app(BookByTitle::class)->find('Dune', [$author], 'en');

    expect($book?->ref)->toBe($found)
        ->and($searched)->toBe($found === '312460' ? ['Dune'] : ['Dune', "Dune {$author}"]);
})->with([
    'its own author' => ['Frank Herbert', '312460'],
    'another writer' => ['Someone Else', null],
]);

test('a book whose edition our book catalogue does not know is found by its title and authors', function () {
    enableBooksModule();
    $film = MediaItem::factory()->movie()->create(['metadata' => ['wikidataId' => 'Q1']]);
    fakeWikidataAcrossMedia([], [
        acrossMediaRow('Q1', TitleRelation::BasedOn, 'Q74287', ['literary' => 'true', 'label' => 'The Hobbit', 'authors' => 'J. R. R. Tolkien']),
    ], editions: [['Q74287', 'isbn', '978-88-7638-255-0'], ['Q74287', 'goodreads', '74019945']], otherwise: function (Request $request) {
        $query = (string) data_get($request->data(), 'query');

        return match (true) {
            str_contains($query, 'query Search') => Http::response(jsonFixture('hardcover/search')),
            str_contains($query, 'query BookCards') => Http::response(hardcoverBookCards()),
            default => Http::response(['data' => []]),
        };
    });

    app(RefreshTitleRelations::class)->execute(MediaItem::query()->whereKey($film->id)->get(), 'en');

    expect(RelatedTitle::query()->where('media_item_id', $film->id)->get(['relation', 'type', 'ref'])->toArray())
        ->toBe([['relation' => 'based_on', 'type' => 'book', 'ref' => '2459845']]);
});

test('the books of a batch cost Hardcover one request for their ids and one for their cards, however many there are', function () {
    enableBooksModule();
    $film = MediaItem::factory()->movie()->create(['metadata' => ['wikidataId' => 'Q1']]);
    $hobbit = jsonFixture('hardcover/book')['data']['books_by_pk'];
    fakeWikidataAcrossMedia([], [
        acrossMediaRow('Q1', TitleRelation::BasedOn, 'Q10', ['literary' => 'true', 'label' => 'First Novel']),
        acrossMediaRow('Q1', TitleRelation::BasedOn, 'Q11', ['literary' => 'true', 'label' => 'Second Novel']),
        acrossMediaRow('Q1', TitleRelation::BasedOn, 'Q12', ['literary' => 'true', 'label' => 'Third Novel']),
    ], editions: [['Q10', 'goodreads', '10'], ['Q11', 'goodreads', '11'], ['Q12', 'goodreads', '12']], otherwise: function (Request $request) use ($hobbit) {
        $query = (string) data_get($request->data(), 'query');
        $ids = (array) data_get($request->data(), 'variables.ids');

        return match (true) {
            str_contains($query, 'query ByGoodreads') => Http::response(['data' => ['book_mappings' => array_map(fn (string $id): array => ['external_id' => $id, 'book_id' => 100 + (int) $id], $ids)]]),
            str_contains($query, 'query BookCards') => Http::response(['data' => ['books' => array_map(fn (int $id): array => ['id' => $id, 'title' => "Novel {$id}"] + $hobbit, $ids)]]),
            default => Http::response([], 404),
        };
    });

    app(RefreshTitleRelations::class)->execute(MediaItem::query()->whereKey($film->id)->get(), 'en');

    expect(RelatedTitle::query()->where('media_item_id', $film->id)->orderBy('ref')->pluck('ref')->all())->toBe(['110', '111', '112']);
    Http::assertSentCount(5);
    Http::assertSent(fn (Request $request) => str_contains((string) data_get($request->data(), 'query'), 'query BookCards'));
});
