<?php

use App\Actions\Media\RefreshDueRelations;
use App\Actions\Media\RefreshTitleRelations;
use App\Catalog\Relations\RelationStore;
use App\Catalog\Relations\TitleRelation;
use App\Catalog\Relations\TitleTie;
use App\Catalog\Wikidata\RelatedWorks;
use App\Catalog\Wikidata\TitleItems;
use App\Models\FranchiseMember;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\Play;
use App\Models\RelatedTitle;
use App\Models\User;
use App\Tasks\TaskName;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

/**
 * One row of a Wikidata answer: the title asked about, how the other work
 * stands to it, and the other work's film or show id at TMDB.
 *
 * @return array<string, array{type: string, value: string}>
 */
function wikidataTie(string $subject, TitleRelation $relation, ?string $movie = null, ?string $show = null): array
{
    return array_filter([
        's' => ['type' => 'uri', 'value' => "http://www.wikidata.org/entity/{$subject}"],
        'rel' => ['type' => 'literal', 'value' => $relation->value],
        'o' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q999'],
        'movie' => $movie === null ? null : ['type' => 'literal', 'value' => $movie],
        'show' => $show === null ? null : ['type' => 'literal', 'value' => $show],
    ]);
}

/**
 * Wikidata answering with the given rows (the question about films and
 * shows, the one across media, the one finding our titles' items), TMDB
 * with the given details by path.
 *
 * @param  list<array<string, mixed>>  $rows
 * @param  array<string, array<string, mixed>>  $tmdb  path => details
 * @param  list<array<string, mixed>>  $acrossMedia
 * @param  list<array<string, mixed>>  $items
 */
function fakeRelations(array $rows, array $tmdb = [], array $acrossMedia = [], array $items = []): void
{
    Http::fake(function (Request $request) use ($rows, $tmdb, $acrossMedia, $items) {
        if (str_contains($request->url(), '/sparql')) {
            $query = (string) ($request->data()['query'] ?? '');

            return Http::response(['results' => ['bindings' => match (true) {
                str_contains($query, 'wdt:P4969') => $acrossMedia,
                str_contains($query, 'SELECT ?id ?value ?item') => $items,
                default => $rows,
            }]]);
        }

        foreach ($tmdb as $path => $details) {
            if (str_contains($request->url(), "/{$path}?")) {
                return Http::response($details);
            }
        }

        return Http::response(['status_code' => 34], 404);
    });
}

/** Files a stored title under a franchise, as the run does. */
function inFranchise(MediaItem $title, string $franchise): void
{
    app(RelationStore::class)->linkFranchises($title, [$franchise]);
}

function titleKnownToWikidata(string $qid, array $attributes = []): MediaItem
{
    return MediaItem::factory()->show()->create(['metadata' => ['wikidataId' => $qid], ...$attributes]);
}

test('one question covers a batch, asks for both ends of every tie and leaves the unwanted out', function () {
    fakeRelations([wikidataTie('Q1079', TitleRelation::SpinOff, show: '60059')]);

    $works = app(RelatedWorks::class)->of(['Q1079', 'Q17738', 'not-an-item; DROP'])->ties;

    expect(array_keys($works))->toBe(['Q1079'])
        ->and($works['Q1079'][0]->relation)->toBe(TitleRelation::SpinOff)
        ->and($works['Q1079'][0]->work->kind->value)->toBe('show')
        ->and($works['Q1079'][0]->work->externalIds)->toBe(['tmdb' => '60059']);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $query = $request->data()['query'];

        return str_contains($query, 'VALUES ?s { wd:Q1079 wd:Q17738 }')
            && str_contains($query, '?s wdt:P2512 ?o') && str_contains($query, '?o wdt:P2512 ?s')
            && str_contains($query, '?s wdt:P156 ?o') && str_contains($query, '?o wdt:P155 ?s')
            // Pornographic film and its parody, parody, fan film.
            && str_contains($query, 'wd:Q185529') && str_contains($query, 'wd:Q16254232') && str_contains($query, 'wd:Q2301591')
            && ! str_contains($query, 'DROP');
    });
});

test('a tied work becomes a card: from the library when stored, from the provider when not', function () {
    $show = titleKnownToWikidata('Q1079');
    $stored = MediaItem::factory()->movie()->create(['provider_ref' => '559969', 'title' => 'El Camino', 'release_date' => '2019-10-11']);
    fakeRelations(
        [wikidataTie('Q1079', TitleRelation::SpinOff, show: '60059'), wikidataTie('Q1079', TitleRelation::SpinOff, movie: '559969')],
        ['tv/60059' => [...jsonFixture('tmdb/tv'), 'id' => 60059, 'name' => 'Better Call Saul', 'first_air_date' => '2015-02-08', 'poster_path' => '/bcs.jpg']],
    );

    expect(app(RefreshTitleRelations::class)->execute(MediaItem::query()->whereKey($show->id)->get(), 'en'))->toBe(2);

    $ties = app(RelationStore::class)->of($show);

    expect(array_map(fn (TitleTie $tie) => [$tie->relation, $tie->media->type, $tie->media->ref, $tie->media->title, $tie->media->posterPath], $ties))
        ->toBe([
            [TitleRelation::SpinOff, 'show', '60059', 'Better Call Saul', '/bcs.jpg'],
            [TitleRelation::SpinOff, 'movie', '559969', 'El Camino', $stored->poster_path],
        ]);
});

test('adult material and a work the catalogue lacks are left out; the rest is kept as the source gave it', function () {
    $film = MediaItem::factory()->movie()->create(['release_date' => '1977-05-25', 'metadata' => ['wikidataId' => 'Q17738', 'collection' => ['ref' => '10', 'name' => 'Star Wars Collection']]]);
    $movie = jsonFixture('tmdb/movie');
    fakeRelations(
        [
            wikidataTie('Q17738', TitleRelation::Next, movie: '1891'),
            wikidataTie('Q17738', TitleRelation::SpinOff, movie: '96697'),
            wikidataTie('Q17738', TitleRelation::SpinOff, movie: '404404'),
            wikidataTie('Q17738', TitleRelation::Next, movie: '74849'),
        ],
        [
            'movie/1891' => [...$movie, 'id' => 1891, 'belongs_to_collection' => ['id' => 10, 'name' => 'Star Wars Collection']],
            'movie/96697' => [...$movie, 'id' => 96697, 'adult' => true, 'belongs_to_collection' => null],
            'movie/74849' => [...$movie, 'id' => 74849, 'belongs_to_collection' => null],
        ],
    );

    app(RefreshTitleRelations::class)->execute(MediaItem::query()->whereKey($film->id)->get(), 'en');

    // The part of the film's own collection is stored too: whether a page
    // shows it is a rule applied when the ties are read.
    expect(RelatedTitle::query()->where('media_item_id', $film->id)->orderBy('ref')->pluck('ref')->all())->toBe(['1891', '74849'])
        ->and(app(RelationStore::class)->of($film))->toBe([]);
});

test('the run asks about what was never asked, notes an empty answer and comes back after months', function () {
    $tied = titleKnownToWikidata('Q1079');
    $alone = titleKnownToWikidata('Q5');
    // Its provider names no item: looked for by its TMDB id, and not found.
    MediaItem::factory()->movie()->create();
    RelatedTitle::factory()->create(['media_item_id' => $tied->id, 'ref' => '111']);
    fakeRelations([wikidataTie('Q1079', TitleRelation::SpinOff, show: '60059')], ['tv/60059' => [...jsonFixture('tmdb/tv'), 'id' => 60059]]);

    $run = runScheduledTask(TaskName::RefreshRelations->value);

    expect($run->summary)->toBe('Asked about 3 of 3 titles due. Kept 1 ties and 0 titles of their franchises.')
        // What the source no longer says is gone.
        ->and(RelatedTitle::query()->pluck('ref')->all())->toBe(['60059'])
        ->and(app(RelationStore::class)->of($alone))->toBe([])
        ->and(runScheduledTask(TaskName::RefreshRelations->value)->summary)->toBe('Asked about 0 of 0 titles due. Kept 0 ties and 0 titles of their franchises.');

    $this->travel(RelationStore::FRESH_DAYS + 1)->days();

    expect(app(RelationStore::class)->countDue(now(), app(TitleItems::class)->findableTypes()))->toBe(3);
});

test('a source failing title after title ends the run; each title it failed on waits for the next run', function () {
    $titles = array_map(fn (int $number) => titleKnownToWikidata("Q{$number}"), range(1, RefreshDueRelations::FAILURES_BEFORE_STOP + 1));
    Http::fake(['*' => Http::response([], 503)]);

    $run = runScheduledTask(TaskName::RefreshRelations->value);
    $due = fn (): int => app(RelationStore::class)->countDue(now(), app(TitleItems::class)->findableTypes());

    $stop = RefreshDueRelations::FAILURES_BEFORE_STOP;

    expect($run->summary)->toBe('Asked about '.$stop.' of '.($stop + 1).' titles due. Kept 0 ties and 0 titles of their franchises.')
        ->and($run->errors)->toHaveCount($stop)
        ->and($run->errors[0])->toStartWith("{$titles[0]->title}: ")
        ->and($due())->toBe(1);

    $this->travel(RefreshDueRelations::HOURS_AFTER_FAILURE + 1)->hours();

    expect($due())->toBe($stop + 1);
});

test('a service\'s limit is waited out and the batch asked again', function () {
    Sleep::fake();
    titleKnownToWikidata('Q1079');
    $answered = 0;
    Http::fake(function () use (&$answered) {
        return $answered++ === 0
            ? Http::response([], 429, ['Retry-After' => '5'])
            : Http::response(['results' => ['bindings' => []]]);
    });

    $run = runScheduledTask(TaskName::RefreshRelations->value);

    expect($run->summary)->toBe('Asked about 1 of 1 titles due. Kept 0 ties and 0 titles of their franchises.')
        ->and($run->errors)->toBeNull();
    Sleep::assertSequence([Sleep::for(5)->seconds()]);
});

test('a batch too heavy to answer is asked in halves, and only the title behind it waits a day', function () {
    foreach (['Q1', 'Q2', 'Q3'] as $qid) {
        titleKnownToWikidata($qid);
    }
    $heavy = titleKnownToWikidata('Q999999', ['title' => 'The Heavy One']);
    Http::fake(fn (Request $request) => str_contains((string) ($request->data()['query'] ?? ''), 'wd:Q999999')
        ? Http::response([], 502)
        : Http::response(['results' => ['bindings' => []]]));

    $run = runScheduledTask(TaskName::RefreshRelations->value);

    expect($run->summary)->toBe('Asked about 4 of 4 titles due. Kept 0 ties and 0 titles of their franchises.')
        ->and($run->errors)->toHaveCount(1)
        ->and($run->errors[0])->toStartWith('The Heavy One: ')
        ->and(app(RelationStore::class)->countDue(now(), app(TitleItems::class)->findableTypes()))->toBe(0);

    $this->travel(RefreshDueRelations::HOURS_AFTER_FAILURE + 1)->hours();

    expect(app(RelationStore::class)->due(now(), 10, app(TitleItems::class)->findableTypes())->pluck('id')->all())->toBe([$heavy->id]);
});

test('a batch is as large as the job says', function () {
    foreach (range(1, RefreshDueRelations::BATCH + 1) as $number) {
        titleKnownToWikidata('Q'.(1000 + $number));
    }

    fakeRelations([]);
    runScheduledTask(TaskName::RefreshRelations->value);

    // Two batches, each one question about films and shows and one across media.
    expect(Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), '/sparql')))->toHaveCount(4);
});

test('a title\'s page shows its ties with their states, without asking anyone', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['provider_ref' => '603', 'release_date' => '1999-03-31']);
    RelatedTitle::factory()->create(['media_item_id' => $movie->id, 'relation' => TitleRelation::Next, 'type' => 'show', 'ref' => '777', 'title' => 'The Series']);
    inFranchise($movie, 'Q462');
    FranchiseMember::factory()->create(['title' => 'A Fellow Film']);
    Http::fake([...providerDictionaryFakes(), '*themoviedb.org/3/movie/603*' => Http::response(jsonFixture('tmdb/movie')), '*' => Http::response([], 404)]);

    $this->actingAs($user)
        ->get(route('movie.details', ['ref' => '603']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('relatedTitles', fn ($ties) => collect($ties)->map(fn ($tie) => [$tie['relation'], $tie['label'], $tie['media']['title']])->all() === [['next', 'Continuation', 'The Series'], ['same_universe', null, 'A Fellow Film']])
            ->has('userStates.show:777'));

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'wikidata') && str_contains((string) ($request->data()['query'] ?? ''), 'P2512'));
});

test('a tie is read from both ends, so a title that was never asked about still names its original', function () {
    $original = MediaItem::factory()->movie()->create(['title' => 'The Big Lebowski', 'release_date' => '1998-03-06']);
    $spinOff = MediaItem::factory()->movie()->create(['title' => 'The Jesus Rolls', 'provider_ref' => '500', 'release_date' => '2019-10-17']);
    RelatedTitle::factory()->create(['media_item_id' => $original->id, 'relation' => TitleRelation::SpinOff, 'type' => 'movie', 'ref' => '500', 'title' => 'The Jesus Rolls']);

    $ties = app(RelationStore::class)->of($spinOff);

    expect(array_map(fn (TitleTie $tie) => [$tie->relation, $tie->media->title], $ties))->toBe([[TitleRelation::SpinOffOf, 'The Big Lebowski']]);
});

test('"follows" between two films is not shown: a collection orders a story, a franchise muddles it', function () {
    $film = MediaItem::factory()->movie()->create(['release_date' => '1977-05-25']);
    RelatedTitle::factory()->create(['media_item_id' => $film->id, 'relation' => TitleRelation::Next, 'type' => 'movie', 'title' => 'Holiday Special']);
    RelatedTitle::factory()->create(['media_item_id' => $film->id, 'relation' => TitleRelation::SpinOff, 'type' => 'movie', 'title' => 'A Spin-off Film']);
    RelatedTitle::factory()->create(['media_item_id' => $film->id, 'relation' => TitleRelation::Previous, 'type' => 'show', 'title' => 'The Series', 'release_date' => '2014-10-13']);

    expect(array_map(fn (TitleTie $tie) => [$tie->relation, $tie->media->title], app(RelationStore::class)->of($film)))
        // The show came out later, whatever the source says of the story's order.
        ->toBe([[TitleRelation::SpinOff, 'A Spin-off Film'], [TitleRelation::Next, 'The Series']]);
});

test('a pair filed both ways round, or as a spin-off too, is shown once', function () {
    $show = MediaItem::factory()->show()->create(['release_date' => '2011-06-23']);
    $pair = ['media_item_id' => $show->id, 'type' => 'show', 'ref' => '9', 'title' => 'Pearson', 'release_date' => '2019-07-17'];
    RelatedTitle::factory()->create([...$pair, 'relation' => TitleRelation::Next]);
    RelatedTitle::factory()->create([...$pair, 'relation' => TitleRelation::SpinOff]);
    $film = ['media_item_id' => $show->id, 'type' => 'movie', 'ref' => '8', 'title' => 'The Film', 'release_date' => '1992-08-28'];
    RelatedTitle::factory()->create([...$film, 'relation' => TitleRelation::Next]);
    RelatedTitle::factory()->create([...$film, 'relation' => TitleRelation::Previous]);

    expect(array_map(fn (TitleTie $tie) => [$tie->relation, $tie->media->title], app(RelationStore::class)->of($show)))
        ->toBe([[TitleRelation::SpinOff, 'Pearson'], [TitleRelation::Previous, 'The Film']]);
});

test('the franchise a title names is read with its ties, in the same question', function () {
    fakeRelations([[
        's' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q17738'],
        'rel' => ['type' => 'literal', 'value' => 'franchise'],
        'o' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q462'],
    ]]);

    $answer = app(RelatedWorks::class)->of(['Q17738']);

    expect($answer->franchises)->toBe(['Q17738' => ['Q462']])
        ->and($answer->ties)->toBe([]);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->data()['query'], '?s wdt:P8345|wdt:P1434 ?o'));
});

test('the run lists each franchise once and keeps its films and shows as cards', function () {
    $film = MediaItem::factory()->movie()->create(['provider_ref' => '11', 'title' => 'Star Wars', 'metadata' => ['wikidataId' => 'Q17738']]);
    $movie = jsonFixture('tmdb/movie');
    Http::fake(function (Request $request) use ($movie) {
        $query = (string) ($request->data()['query'] ?? '');

        return match (true) {
            str_contains($query, 'VALUES ?s { wd:Q17738 }') => Http::response(['results' => ['bindings' => [[
                's' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q17738'],
                'rel' => ['type' => 'literal', 'value' => 'franchise'],
                'o' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q462'],
            ]]]]),
            str_contains($query, 'VALUES ?s { wd:Q462 }') => Http::response(['results' => ['bindings' => [
                ['s' => ['value' => 'http://www.wikidata.org/entity/Q462'], 'movie' => ['value' => '11']],
                ['s' => ['value' => 'http://www.wikidata.org/entity/Q462'], 'movie' => ['value' => '330459']],
                ['s' => ['value' => 'http://www.wikidata.org/entity/Q462'], 'movie' => ['value' => '96697']],
            ]]]),
            str_contains($request->url(), '/movie/330459?') => Http::response([...$movie, 'id' => 330459, 'vote_count' => 15000, 'belongs_to_collection' => null]),
            str_contains($request->url(), '/movie/96697?') => Http::response([...$movie, 'id' => 96697, 'adult' => true]),
            default => Http::response(['status_code' => 34], 404),
        };
    });

    $run = runScheduledTask(TaskName::RefreshRelations->value);

    expect($run->summary)->toBe('Asked about 1 of 1 titles due. Kept 0 ties and 2 titles of their franchises.')
        ->and(FranchiseMember::query()->orderBy('ref')->get()->map(fn ($member) => [$member->franchise, $member->ref, $member->title, $member->votes])->all())
        // The stored film carries no count: it is the user's own.
        ->toBe([['Q462', '11', 'Star Wars', null], ['Q462', '330459', 'The Matrix', 15000]])
        ->and(array_map(fn (TitleTie $tie) => [$tie->relation, $tie->media->title], app(RelationStore::class)->of($film)))
        ->toBe([[TitleRelation::SameUniverse, 'The Matrix']])
        // Listed: not asked about again until it ages.
        ->and(app(RelationStore::class)->franchisesDue(now(), 10))->toBe([]);
});

test('of a franchise the page shows the rest, known widely enough, outside the film\'s own collection', function () {
    $film = MediaItem::factory()->movie()->create(['provider_ref' => '11', 'metadata' => ['collection' => ['ref' => '10', 'name' => 'Star Wars Collection']]]);
    inFranchise($film, 'Q462');
    FranchiseMember::factory()->create(['ref' => '11', 'title' => 'Itself']);
    FranchiseMember::factory()->create(['title' => 'A part of its collection', 'collection_ref' => '10']);
    FranchiseMember::factory()->create(['title' => 'A tie-in short', 'votes' => config()->integer('tracker.widely_known_votes.movie') - 1]);
    FranchiseMember::factory()->create(['type' => 'show', 'title' => 'A little-seen show', 'votes' => config()->integer('tracker.widely_known_votes.show') - 1]);
    FranchiseMember::factory()->create(['type' => 'show', 'title' => 'A show', 'votes' => config()->integer('tracker.widely_known_votes.show'), 'release_date' => '2020-01-01']);
    FranchiseMember::factory()->create(['title' => 'Stored here', 'votes' => null, 'release_date' => '2019-01-01']);
    FranchiseMember::factory()->create(['title' => 'Another branch', 'collection_ref' => '20', 'release_date' => '2016-01-01']);
    FranchiseMember::factory()->create(['franchise' => 'Q999', 'title' => 'Another universe']);

    expect(array_map(fn (TitleTie $tie) => $tie->media->title, app(RelationStore::class)->of($film)))->toBe(['Another branch', 'Stored here', 'A show']);
});

test('a title of the franchise that is also a spin-off says so, and one that only "follows" does not', function () {
    $show = MediaItem::factory()->show()->create(['release_date' => '2008-01-20']);
    inFranchise($show, 'Q113461786');
    FranchiseMember::factory()->create(['franchise' => 'Q113461786', 'type' => 'show', 'ref' => '60059', 'title' => 'Better Call Saul']);
    FranchiseMember::factory()->create(['franchise' => 'Q113461786', 'type' => 'movie', 'ref' => '559969', 'title' => 'El Camino', 'release_date' => '2019-10-11']);
    RelatedTitle::factory()->create(['media_item_id' => $show->id, 'relation' => TitleRelation::SpinOff, 'type' => 'show', 'ref' => '60059', 'title' => 'Better Call Saul']);
    RelatedTitle::factory()->create(['media_item_id' => $show->id, 'relation' => TitleRelation::Next, 'type' => 'movie', 'ref' => '559969', 'title' => 'El Camino']);

    expect(array_map(fn (TitleTie $tie) => [$tie->relation, $tie->media->title], app(RelationStore::class)->of($show)))
        ->toBe([[TitleRelation::SpinOff, 'Better Call Saul'], [TitleRelation::SameUniverse, 'El Camino']]);
});

test('finding the tied works costs the same number of queries for one work as for many', function () {
    $queriesFor = function (int $works): int {
        $title = titleKnownToWikidata('Q'.(5000 + $works));
        $rows = [];

        foreach (range(1, $works) as $number) {
            $ref = (string) (70000 + $works * 100 + $number);
            MediaItem::factory()->movie()->create(['provider_ref' => $ref]);
            $rows[] = wikidataTie('Q'.(5000 + $works), TitleRelation::SpinOff, movie: $ref);
        }

        fakeRelations($rows);
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(RefreshTitleRelations::class)->execute(MediaItem::query()->whereKey($title->id)->get(), 'en');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // Whatever the first run of a process asks once is out of the way first.
    $queriesFor(2);

    // The works are looked up together, so a title with many ties asks the
    // database no more often than a title with one.
    expect($queriesFor(8))->toBe($queriesFor(1));
});

test('the titles someone holds are asked about before the rest of the catalogue; a collection\'s own list holds none', function () {
    foreach (range(1, RefreshDueRelations::BATCH) as $number) {
        titleKnownToWikidata("Q{$number}");
    }
    $watched = MediaItem::factory()->movie()->create(['metadata' => ['wikidataId' => 'Q500']]);
    Play::factory()->for(User::factory())->create(['media_item_id' => $watched->id]);
    projectEveryLibrary();
    $listed = titleKnownToWikidata('Q501');
    ListItem::factory()->create(['list_id' => MediaList::factory()->create()->id, 'media_item_id' => $listed->id]);
    $collected = titleKnownToWikidata('Q502');
    ListItem::factory()->create(['list_id' => MediaList::factory()->collection('tmdb', '10')->create()->id, 'media_item_id' => $collected->id]);
    $asked = [];
    Http::fake(function (Request $request) use (&$asked) {
        $asked[] = (string) ($request->data()['query'] ?? '');

        return Http::response(['results' => ['bindings' => []]]);
    });

    runScheduledTask(TaskName::RefreshRelations->value);

    expect($asked[0])->toContain('VALUES ?s { wd:Q500 wd:Q501 }')
        ->and(app(RelationStore::class)->countDue(now(), app(TitleItems::class)->findableTypes()))->toBe(0);
});
