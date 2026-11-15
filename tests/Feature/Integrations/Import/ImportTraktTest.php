<?php

use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportSelection;
use App\Models\MediaItem;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a Trakt export ZIP in the fake local disk and runs the import.
 *
 * @param  array<string, mixed>  $files  archive file name => JSON-encodable body
 * @param  array{categories?: list<string>, list_ids?: list<int>}  $selection
 */
function runTraktImport(User $user, array $files, array $selection): TaskRun
{
    Storage::fake('local');

    $tmp = scratchPath('trakt', '.zip');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::CREATE);
    foreach ($files as $name => $body) {
        $zip->addFromString($name, (string) json_encode($body));
    }
    $zip->close();

    Storage::disk('local')->put('imports/test.zip', (string) file_get_contents($tmp));
    unlink($tmp);

    return runImportFile($user, 'trakt', 'imports/test.zip', new ImportSelection(
        $selection['categories'] ?? [],
        array_map('strval', $selection['list_ids'] ?? []),
    ));
}

/**
 * A representative export: a watched movie + episode, two ratings, a
 * watchlist entry, a favorite and a one-item custom list — all pointing at
 * the two pre-persisted positions so the resolver never touches the network.
 *
 * @return array<string, mixed>
 */
function traktFixture(string $showRef): array
{
    return [
        'user-profile.json' => ['username' => 'tester'],
        'watched-history-1.json' => [
            ['watched_at' => '2025-01-10T20:00:00.000Z', 'action' => 'watch', 'type' => 'movie', 'movie' => ['title' => 'The Matrix', 'ids' => ['tmdb' => 603]]],
            ['watched_at' => '2025-01-11T21:00:00.000Z', 'action' => 'watch', 'type' => 'episode', 'episode' => ['title' => 'Pilot', 'season' => 1, 'number' => 1, 'ids' => ['tmdb' => 111]], 'show' => ['title' => 'Some Show', 'ids' => ['tmdb' => (int) $showRef]]],
        ],
        'ratings-movies-1.json' => [
            ['rated_at' => '2025-01-10T20:05:00.000Z', 'rating' => 8, 'type' => 'movie', 'movie' => ['title' => 'The Matrix', 'ids' => ['tmdb' => 603]]],
        ],
        'ratings-shows-1.json' => [
            ['rated_at' => '2025-01-09T10:00:00.000Z', 'rating' => 9, 'type' => 'show', 'show' => ['title' => 'Some Show', 'ids' => ['tmdb' => (int) $showRef]]],
        ],
        'lists-watchlist.json' => [
            ['type' => 'movie', 'listed_at' => '2025-02-01T00:00:00.000Z', 'movie' => ['title' => 'The Matrix', 'ids' => ['tmdb' => 603]]],
        ],
        'lists-favorites.json' => [
            ['type' => 'show', 'listed_at' => '2025-03-01T00:00:00.000Z', 'show' => ['title' => 'Some Show', 'ids' => ['tmdb' => (int) $showRef]]],
        ],
        'lists-lists.json' => [
            ['name' => 'My List', 'privacy' => 'public', 'item_count' => 1, 'ids' => ['trakt' => 555]],
        ],
        'lists-list-555-1.json' => [
            ['type' => 'movie', 'rank' => 1, 'listed_at' => '2025-04-01T00:00:00.000Z', 'movie' => ['title' => 'The Matrix', 'ids' => ['tmdb' => 603]]],
        ],
    ];
}

test('a Trakt export imports the selected categories', function () {
    Http::fake();

    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['provider_ref' => '603', 'title' => 'The Matrix']);
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-02']]);

    $taskRun = runTraktImport($user, traktFixture($show->provider_ref), [
        'categories' => ['history', 'ratings', 'watchlist', 'favorites', 'lists'],
        'list_ids' => [555],
    ]);

    expect($taskRun->status)->toBe(TaskRunStatus::Success);

    // One movie play + one episode play, both stamped with Trakt's watched_at.
    expect($user->plays()->pluck('watched_at')->map->toDateString()->all())
        ->toContain('2025-01-10', '2025-01-11');

    // Trakt's 1–10 rating maps straight through; backdated to rated_at.
    expect($user->ratings()->pluck('rating', 'media_item_id')->all())
        ->toBe([$movie->id => 8, $show->id => 9]);

    $movieRating = $user->ratings()->where('media_item_id', $movie->id)->firstOrFail();
    expect($movieRating->created_at?->toDateTimeString())->toBe('2025-01-10 20:05:00');

    expect($user->watchlist()->items()->count())->toBe(1)
        ->and($user->favorites()->count())->toBe(1);

    $list = $user->lists()->where('title', 'My List')->firstOrFail();
    expect($list->is_public)->toBeTrue()
        ->and($list->items()->count())->toBe(1);

    // Entries carrying a tmdb id are placed from the file alone.
    Http::assertNothingSent();
});

test('only the selected categories and lists are imported', function () {
    $user = User::factory()->create();
    MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-02']]);

    $taskRun = runTraktImport($user, traktFixture($show->provider_ref), [
        'categories' => ['lists'],
        'list_ids' => [555],
    ]);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($user->plays()->count())->toBe(0)
        ->and($user->ratings()->count())->toBe(0)
        ->and($user->favorites()->count())->toBe(0)
        ->and($user->watchlist()->items()->exists())->toBeFalse();

    expect($user->lists()->where('title', 'My List')->firstOrFail()->items()->count())->toBe(1);
});

test('entries no id can place are skipped and the import is idempotent', function () {
    $user = User::factory()->create();
    MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-02']]);

    Http::fake(['*/find/*' => Http::response(['movie_results' => [], 'tv_results' => []])]);

    $files = traktFixture($show->provider_ref);
    // TMDB has never heard of this imdb id either, so nothing places it.
    $files['watched-history-1.json'][] = ['watched_at' => '2025-05-01T00:00:00.000Z', 'action' => 'watch', 'type' => 'movie', 'movie' => ['title' => 'No TMDB', 'ids' => ['imdb' => 'tt999']]];

    $selection = ['categories' => ['history', 'ratings', 'watchlist', 'favorites', 'lists'], 'list_ids' => [555]];

    $taskRun = runTraktImport($user, $files, $selection);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('skipped entries: 1')
        ->and($user->plays()->count())->toBe(2);

    // The skip says what was tried, so "missing" and "unknown" read apart.
    expect($taskRun->errors)->toContain('No TMDB: no match for imdb tt999.');

    // Re-running the same export adds nothing.
    runTraktImport($user->refresh(), $files, $selection);

    expect($user->plays()->count())->toBe(2)
        ->and($user->ratings()->count())->toBe(2)
        ->and($user->lists()->where('title', 'My List')->firstOrFail()->items()->count())->toBe(1);
});

test('a movie Trakt knows only by its imdb id still lands', function () {
    $user = User::factory()->create();
    MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    $shawshank = MediaItem::factory()->movie()->create(['provider_ref' => '278', 'title' => 'The Shawshank Redemption']);
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-02']]);

    Http::fake(['*/find/tt0111161*' => Http::response(['movie_results' => [['id' => 278]], 'tv_results' => []])]);

    $files = traktFixture($show->provider_ref);
    $files['watched-history-1.json'][] = ['watched_at' => '2025-05-01T00:00:00.000Z', 'action' => 'watch', 'type' => 'movie', 'movie' => ['title' => 'The Shawshank Redemption', 'ids' => ['imdb' => 'tt0111161']]];

    $taskRun = runTraktImport($user, $files, ['categories' => ['history'], 'list_ids' => []]);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('skipped entries: 0')
        ->and($user->plays()->where('media_item_id', $shawshank->id)->count())->toBe(1);
});

test('an episode whose show carries only a tvdb id lands on that episode', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-02']]);

    Http::fake(['*/find/73739*' => Http::response(['movie_results' => [], 'tv_results' => [['id' => (int) $show->provider_ref]]])]);

    // Season and episode numbers ride on top of the show's ref, so placing
    // the show is enough to place anything hanging off it.
    $files = traktFixture($show->provider_ref);
    $files['watched-history-1.json'] = [
        ['watched_at' => '2025-06-01T00:00:00.000Z', 'action' => 'watch', 'type' => 'episode', 'episode' => ['title' => 'Second', 'season' => 1, 'number' => 2, 'ids' => []], 'show' => ['title' => 'Some Show', 'ids' => ['tvdb' => 73739]]],
    ];

    $taskRun = runTraktImport($user, $files, ['categories' => ['history'], 'list_ids' => []]);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($user->plays()->count())->toBe(1);

    $played = MediaItem::query()->findOrFail($user->plays()->value('media_item_id'));
    expect($played->provider_ref)->toBe("{$show->provider_ref}:1:2");
});

test('one unknown id is asked about once, however often it appears', function () {
    $user = User::factory()->create();
    MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-02']]);

    Http::fake(['*/find/*' => Http::response(['movie_results' => [], 'tv_results' => []])]);

    $files = traktFixture($show->provider_ref);
    $movie = ['title' => 'No TMDB', 'ids' => ['imdb' => 'tt999']];
    $files['watched-history-1.json'][] = ['watched_at' => '2025-05-01T00:00:00.000Z', 'action' => 'watch', 'type' => 'movie', 'movie' => $movie];
    $files['watched-history-1.json'][] = ['watched_at' => '2025-05-02T00:00:00.000Z', 'action' => 'watch', 'type' => 'movie', 'movie' => $movie];

    $taskRun = runTraktImport($user, $files, ['categories' => ['history'], 'list_ids' => []]);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('skipped entries: 2');
    Http::assertSentCount(1);
});

test('a provider outage costs the entry, not the import', function () {
    $user = User::factory()->create();
    MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-02']]);

    Http::fake(['*/find/*' => Http::response([], 500)]);

    $files = traktFixture($show->provider_ref);
    $files['watched-history-1.json'][] = ['watched_at' => '2025-05-01T00:00:00.000Z', 'action' => 'watch', 'type' => 'movie', 'movie' => ['title' => 'No TMDB', 'ids' => ['imdb' => 'tt999']]];

    $taskRun = runTraktImport($user, $files, ['categories' => ['history'], 'list_ids' => []]);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('skipped entries: 1')
        ->and($user->plays()->count())->toBe(2);
});

test('a ZIP without the Trakt marker fails cleanly', function () {
    $user = User::factory()->create();

    $taskRun = runTraktImport($user, ['random.json' => ['nope' => true]], ['categories' => ['history'], 'list_ids' => []]);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toContain('not a Trakt export');
});

test('an episode whose show has no TMDB id is placed through the show\'s other ids', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-02']]);

    Http::fake(['*/find/tt0944947*' => Http::response(['movie_results' => [], 'tv_results' => [['id' => (int) $show->provider_ref]]])]);

    $files = [
        'user-profile.json' => ['username' => 'tester'],
        'watched-history-1.json' => [
            ['watched_at' => '2025-01-11T21:00:00.000Z', 'action' => 'watch', 'type' => 'episode', 'episode' => ['title' => 'Second', 'season' => 1, 'number' => 2, 'ids' => []], 'show' => ['title' => 'Some Show', 'ids' => ['imdb' => 'tt0944947']]],
        ],
    ];

    $taskRun = runTraktImport($user, $files, ['categories' => ['history'], 'list_ids' => []]);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($user->plays()->sole()->mediaItem->provider_ref)->toBe("{$show->provider_ref}:1:2");
});

test('every entry the file counts is either placed or said to be passed over', function () {
    $user = User::factory()->create();
    MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-02']]);

    $files = traktFixture($show->provider_ref);
    $files['watched-history-1.json'][] = ['watched_at' => '2025-05-01T00:00:00.000Z', 'action' => 'watch', 'type' => 'show', 'show' => ['title' => 'A Show Event', 'ids' => ['tmdb' => 1399]]];
    $files['ratings-movies.json'][] = ['rated_at' => '2025-05-01T00:00:00.000Z', 'rating' => 'ten', 'type' => 'movie', 'movie' => ['title' => 'Badly Rated', 'ids' => ['tmdb' => 603]]];

    $taskRun = runTraktImport($user, $files, ['categories' => ['history', 'ratings']]);

    expect($taskRun->summary)->toContain('skipped entries: 2')
        ->and($taskRun->errors)->toContain('A Show Event: only movies and episodes are viewings in the history.', 'Badly Rated: no rating value.');
});
