<?php

use App\Actions\Library\MarkWatched;
use App\Catalog\CardSnapshot;
use App\Catalog\Department;
use App\Catalog\Sets\SetKind;
use App\Catalog\Sets\SetStore;
use App\Library\WatchedAt;
use App\Models\CatalogSet;
use App\Models\Person;
use App\Models\User;
use App\Tasks\TaskName;
use Illuminate\Support\Facades\Http;

// The charts every instance asks for are another test's; here the run is
// counted on the collections and people each test sets up.
beforeEach(fn () => onlySuggestionSources(['missing-from-collections', 'missing-from-directors']));

/**
 * @param  list<array<string, mixed>>  $parts
 * @return array<string, mixed>
 */
function tmdbCollectionAnswer(int $id, string $name, array $parts): array
{
    return ['id' => $id, 'name' => $name, 'poster_path' => '/collection.jpg', 'parts' => $parts];
}

test('the run copies the collections users have begun, in each user\'s language', function () {
    $user = User::factory()->create(['locale' => 'pl']);
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '1']), WatchedAt::now());
    Http::fake(['*/collection/10*' => Http::response(tmdbCollectionAnswer(10, 'Saga', [
        ['id' => 2, 'title' => 'Second', 'release_date' => '2003-05-15', 'poster_path' => '/second.jpg'],
        ['id' => 1, 'title' => 'First', 'release_date' => '2001-05-15', 'poster_path' => '/first.jpg'],
    ]))]);

    $run = runScheduledTask(TaskName::RefreshSuggestionSets->value);

    $set = CatalogSet::query()->sole();

    expect($run->summary)->toBe('Fetched 1 of 1 sets due.')
        ->and($set->name)->toBe('Saga')
        ->and($set->locale)->toBe('pl')
        ->and($set->poster_path)->toBe('/collection.jpg')
        ->and($set->fetched_at)->not->toBeNull()
        // In release order, as the provider's collection is read.
        ->and($set->titles->map(fn ($title) => [$title->position, $title->type, $title->ref, CardSnapshot::of($title)->title, CardSnapshot::of($title)->posterPath])->all())
        ->toBe([[1, 'movie', '1', 'First', '/first.jpg'], [2, 'movie', '2', 'Second', '/second.jpg']]);
});

test('the run copies the filmographies of the people users keep watching, a row per credit', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['provider_ref' => '525']);

    foreach (range(1, 3) as $film) {
        finishedFilmWith($user, $person, Department::Directing, 'Director');
    }

    Http::fake(['*/person/525*' => Http::response([
        'id' => 525,
        'name' => 'Ada Director',
        'profile_path' => '/ada.jpg',
        'combined_credits' => [
            'cast' => [['id' => 7, 'media_type' => 'movie', 'title' => 'A Cameo', 'character' => 'Passer-by', 'order' => 12, 'release_date' => '2012-03-01', 'vote_count' => 800, 'popularity' => 3.0]],
            'crew' => [['id' => 8, 'media_type' => 'movie', 'title' => 'Her Film', 'department' => 'Directing', 'job' => 'Director', 'release_date' => '2014-06-01', 'vote_count' => 12000, 'popularity' => 9.0]],
        ],
    ])]);

    runScheduledTask(TaskName::RefreshSuggestionSets->value);

    $set = CatalogSet::query()->where('kind', SetKind::Filmography)->sole();

    expect([$set->name, $set->poster_path, $set->ref])->toBe(['Ada Director', '/ada.jpg', '525'])
        ->and($set->titles->map(fn ($title) => [$title->ref, $title->department, $title->role, $title->billing, $title->votes])->all())
        ->toBe([['8', Department::Directing, 'Director', null, 12000], ['7', Department::Acting, 'Passer-by', 12, 800]]);
});

test('a fresh copy is not asked for again and an aged one is replaced', function () {
    $user = User::factory()->create();
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '1']), WatchedAt::now());
    collectionSet('10', ['1' => '2001-01-01', '9' => '2002-01-01']);
    Http::fake(['*/collection/10*' => Http::response(tmdbCollectionAnswer(10, 'Saga', [['id' => 1, 'title' => 'First', 'release_date' => '2001-05-15', 'poster_path' => '/first.jpg']]))]);

    expect(runScheduledTask(TaskName::RefreshSuggestionSets->value)->summary)->toBe('Fetched 0 of 0 sets due.');
    Http::assertNothingSent();

    $this->travel(SetKind::Collection->freshDays() + 1)->days();

    expect(runScheduledTask(TaskName::RefreshSuggestionSets->value)->summary)->toBe('Fetched 1 of 1 sets due.')
        ->and(CatalogSet::query()->sole()->titles->pluck('ref')->all())->toBe(['1']);
});

test('a collection gone from the provider is forgotten, not an error', function () {
    $user = User::factory()->create();
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '1']), WatchedAt::now());
    Http::fake(['*/collection/10*' => Http::response(['status_code' => 34], 404)]);

    $run = runScheduledTask(TaskName::RefreshSuggestionSets->value);

    expect($run->summary)->toBe('Fetched 0 of 1 sets due. Removed 1 no longer at the provider.')
        ->and($run->errors)->toBeEmpty()
        ->and(CatalogSet::query()->count())->toBe(0);
});

test('a provider that stops answering is left alone for the rest of the run', function () {
    $user = User::factory()->create();
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '1']), WatchedAt::now());
    app(MarkWatched::class)->execute($user, filmOfCollection('20', ['provider_ref' => '2']), WatchedAt::now());
    Http::fake(['*' => Http::response([], 503)]);

    $run = runScheduledTask(TaskName::RefreshSuggestionSets->value);

    expect($run->summary)->toBe('Fetched 0 of 2 sets due.')
        ->and($run->errors)->toHaveCount(1)
        // Both still wait for the next run.
        ->and(CatalogSet::query()->whereNull('fetched_at')->count())->toBe(2);
    // One collection asked (the client's own retry included), the other not at all.
    expect(Http::recorded()->map(fn (array $pair): string => (string) parse_url($pair[0]->url(), PHP_URL_PATH))->unique())->toHaveCount(1);
});

test('a set nobody has needed for a long while is dropped', function () {
    collectionSet('10', ['1' => '2001-01-01']);

    $this->travel(SetStore::KEEP_UNWANTED_DAYS + 1)->days();

    expect(runScheduledTask(TaskName::RefreshSuggestionSets->value)->summary)->toBe('Fetched 0 of 0 sets due. Forgot 1 nobody needs any more.')
        ->and(CatalogSet::query()->count())->toBe(0);
});
