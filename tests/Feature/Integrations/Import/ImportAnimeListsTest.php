<?php

use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Enums\TaskRunStatus;
use App\Integrations\Anime\AnimeIdMap;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Jobs\RunImport;
use App\Models\MediaItem;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function runListImport(User $user, string $source, string $username): TaskRun
{
    $taskRun = TaskRun::queueTask("import-{$source}", $user->id);

    app()->call([
        new RunImport($taskRun, $source, ImportInput::credentials(['username' => $username]), ImportSelection::everything()),
        'handle',
    ]);

    return $taskRun->refresh();
}

/**
 * @param  array<string, mixed>  $listStatus
 * @return array<string, mixed>
 */
function malItem(int $id, string $title, array $listStatus, array $node = []): array
{
    return [
        'node' => ['id' => $id, 'title' => $title, ...$node],
        'list_status' => ['score' => 0, 'num_episodes_watched' => 0, 'num_chapters_read' => 0, 'updated_at' => '2024-01-01T10:00:00+00:00', ...$listStatus],
    ];
}

test('a MyAnimeList profile ticks watched seasons and places films through the anime id map', function () {
    enableMangaModule();
    seedAnimeIdMap();

    Http::fake([
        '*/users/tester/animelist*' => Http::response(['data' => [
            // Completed with three episodes; TMDB has two of them.
            malItem(16498, 'Shingeki no Kyojin', ['status' => 'completed', 'score' => 9, 'num_episodes_watched' => 3, 'finish_date' => '2021-05-01'], ['num_episodes' => 3]),
            malItem(164, 'Mononoke Hime', ['status' => 'completed', 'score' => 10, 'finish_date' => '2020-01-02']),
            malItem(18397, 'Shingeki no Kyojin OVA', ['status' => 'on_hold']),
            malItem(99999, 'Unknown Anime', ['status' => 'dropped']),
        ], 'paging' => []]),
        '*/users/tester/mangalist*' => Http::response(['data' => [
            malItem(44347, 'One Punch-Man', ['status' => 'reading', 'score' => 8, 'num_chapters_read' => 120]),
        ], 'paging' => []]),
        ...tmdbAnimeFakes(),
    ]);

    ['show' => $show, 'film' => $film] = animeCatalogue();
    $manga = MediaItem::factory()->manga()->create(['provider_ref' => '44347']);
    $user = User::factory()->create();

    $taskRun = runListImport($user, 'mal', 'tester');

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('plays: 3', 'ratings: 3', 'statuses: 1', 'skipped entries: 3');

    // Both known episodes of season 1, on the day the season was finished.
    $episodes = $user->plays()->whereHas('mediaItem', fn ($q) => $q->where('type', 'episode'))->get();

    expect($episodes)->toHaveCount(2)
        ->and($episodes->every(fn ($play) => $play->watched_at->toDateString() === '2021-05-01'))->toBeTrue()
        ->and($user->ratings()->where('media_item_id', $show->id)->value('rating'))->toBe(9)
        ->and($user->plays()->where('media_item_id', $film->id)->firstOrFail()->watched_at->toDateString())->toBe('2020-01-02')
        ->and($user->mediaStates()->where('media_item_id', $manga->id)->firstOrFail()->status)->toBe(MediaUserStatus::InProgress)
        ->and($user->mediaStates()->where('media_item_id', $manga->id)->firstOrFail()->progress)->toBe(120);

    expect($taskRun->errors)->toContain(
        'Shingeki no Kyojin OVA (MAL 18397): a special without an id of its own in the anime id map.',
        'Unknown Anime (MAL 99999): not in the anime id map, so it has no TVDB or IMDb id to go by.',
    );

    // The client id travels as the header MAL reads it from.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/users/tester/animelist')
        && $request->header('X-MAL-CLIENT-ID') === ['testing-id']);
});

test('a dropped season keeps its drop after the watched episodes', function () {
    enableMangaModule();
    seedAnimeIdMap();

    Http::fake([
        '*/users/tester/animelist*' => Http::response(['data' => [
            malItem(16498, 'Shingeki no Kyojin', ['status' => 'dropped', 'num_episodes_watched' => 1, 'updated_at' => '2024-02-01T10:00:00+00:00']),
        ], 'paging' => []]),
        '*/users/tester/mangalist*' => Http::response(['data' => [], 'paging' => []]),
        ...tmdbAnimeFakes(),
    ]);

    ['show' => $show] = animeCatalogue();
    $user = User::factory()->create();

    runListImport($user, 'mal', 'tester');

    expect($user->plays()->where('outcome', PlayOutcome::Completed->value)->count())->toBe(1)
        ->and($user->plays()->where('media_item_id', $show->id)->where('outcome', PlayOutcome::Dropped->value)->exists())->toBeTrue();
});

test('an unknown MyAnimeList user ends the run with the reason, after one request', function () {
    enableMangaModule();
    seedAnimeIdMap();

    Http::fake(['*/users/nobody/*' => Http::response(['error' => 'not_found'], 404)]);

    $taskRun = runListImport(User::factory()->create(), 'mal', 'nobody');

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe('MyAnimeList has no user named nobody.');

    Http::assertSentCount(1);
});

test('MyAnimeList is only offered with a MAL client id', function () {
    expect(app(ImportSourceRegistry::class)->has('mal'))->toBeFalse()
        ->and(app(ImportSourceRegistry::class)->has('anilist'))->toBeTrue();
});

test('an AniList profile reads its status lists and leaves custom lists out', function () {
    seedAnimeIdMap();

    $entry = fn (int $idMal, string $title, string $status, float $score = 0, int $progress = 0) => [
        'media' => ['idMal' => $idMal, 'title' => ['userPreferred' => $title], 'episodes' => 25],
        'status' => $status,
        'score' => $score,
        'progress' => $progress,
        'startedAt' => ['year' => null, 'month' => null, 'day' => null],
        'completedAt' => ['year' => 2019, 'month' => 7, 'day' => null],
        'updatedAt' => 1717200000,
    ];

    Http::fake([
        'graphql.anilist.co*' => Http::response(['data' => [
            'anime' => ['lists' => [
                ['isCustomList' => false, 'entries' => [$entry(164, 'Princess Mononoke', 'COMPLETED', 8.6)]],
                // A custom list repeats an entry of a status list.
                ['isCustomList' => true, 'entries' => [$entry(164, 'Princess Mononoke', 'COMPLETED', 8.6)]],
                ['isCustomList' => false, 'entries' => [[
                    ...$entry(0, 'Unmapped Show', 'PLANNING'),
                    'media' => ['idMal' => null, 'title' => ['userPreferred' => 'Unmapped Show']],
                ]]],
            ]],
            'manga' => ['lists' => []],
        ]]),
        ...tmdbAnimeFakes(),
    ]);

    ['film' => $film] = animeCatalogue();
    $user = User::factory()->create();

    $taskRun = runListImport($user, 'anilist', 'tester');

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($user->plays()->where('media_item_id', $film->id)->count())->toBe(1)
        ->and($user->plays()->where('media_item_id', $film->id)->firstOrFail()->watched_at->toDateString())->toBe('2019-07-01')
        ->and($user->ratings()->where('media_item_id', $film->id)->value('rating'))->toBe(9)
        ->and($taskRun->errors)->toBe(['Unmapped Show: AniList knows no MyAnimeList id for it, so it cannot be placed.']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'graphql.anilist.co')
        && data_get($request->data(), 'variables.userName') === 'tester');
});

test('a private AniList profile ends the run with the reason', function () {
    seedAnimeIdMap();

    Http::fake(['graphql.anilist.co*' => Http::response(['errors' => [['message' => 'Private User', 'status' => 404]], 'data' => null], 404)]);

    $taskRun = runListImport(User::factory()->create(), 'anilist', 'hidden');

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe('The AniList profile of hidden is private.');
});

test('a tracker still busy after the tries says it asked to wait', function () {
    seedAnimeIdMap();
    Http::fake(['graphql.anilist.co*' => Http::response([], 429)]);

    $taskRun = runListImport(User::factory()->create(), 'anilist', 'tester');

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe('AniList asked to wait before more requests. Try again later.');
});

test('a Kitsu library is read page by page and placed by its MyAnimeList mappings', function () {
    // More requests than the provider allows per second: its rate limiter
    // waits for the window to pass, which a frozen clock never lets happen.
    $this->travelBack();

    seedAnimeIdMap();

    $page = fn (array $entries, array $included, ?string $next) => ['data' => $entries, 'included' => $included, 'links' => $next === null ? [] : ['next' => $next]];

    Http::fake([
        'kitsu.app/api/edge/users*' => Http::response(['data' => [['id' => '42', 'type' => 'users']]]),
        'kitsu.app/api/edge/library-entries?page=2*' => Http::response($page(
            [
                ['id' => '2', 'type' => 'libraryEntries', 'attributes' => ['status' => 'current', 'progress' => 3, 'ratingTwenty' => null, 'updatedAt' => '2024-01-01T00:00:00.000Z'], 'relationships' => ['anime' => ['data' => null]]],
                // A relationship that names no id is one entry passed over, not the end of the run.
                ['id' => '3', 'type' => 'libraryEntries', 'attributes' => ['status' => 'current', 'progress' => 1, 'updatedAt' => '2024-01-02T00:00:00.000Z'], 'relationships' => ['anime' => ['data' => ['type' => 'anime']]]],
            ],
            [],
            null,
        )),
        'kitsu.app/api/edge/library-entries*filter%5Bkind%5D=anime*' => Http::response($page(
            [['id' => '1', 'type' => 'libraryEntries', 'attributes' => ['status' => 'completed', 'progress' => 1, 'ratingTwenty' => 17, 'finishedAt' => '2018-03-04T12:00:00.000Z', 'updatedAt' => '2018-03-04T12:00:00.000Z'], 'relationships' => ['anime' => ['data' => ['type' => 'anime', 'id' => '7']]]]],
            [
                ['id' => '7', 'type' => 'anime', 'attributes' => ['canonicalTitle' => 'Princess Mononoke', 'episodeCount' => 1], 'relationships' => ['mappings' => ['data' => [['type' => 'mappings', 'id' => '70']]]]],
                ['id' => '70', 'type' => 'mappings', 'attributes' => ['externalSite' => 'myanimelist/anime', 'externalId' => '164']],
            ],
            'https://kitsu.app/api/edge/library-entries?page=2',
        )),
        'kitsu.app/api/edge/library-entries*' => Http::response($page([], [], null)),
        ...tmdbAnimeFakes(),
    ]);

    ['film' => $film] = animeCatalogue();
    $user = User::factory()->create();

    $taskRun = runListImport($user, 'kitsu', 'someone');

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($user->plays()->where('media_item_id', $film->id)->firstOrFail()->watched_at->toDateString())->toBe('2018-03-04')
        // 17 of 20 is 8.5, rounded up.
        ->and($user->ratings()->where('media_item_id', $film->id)->value('rating'))->toBe(9)
        ->and($taskRun->summary)->toContain('skipped entries: 2')
        ->and($taskRun->errors)->toBe(['A Kitsu library entry: Kitsu hides this title from public reads, so it cannot be placed.']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'filter%5Buser_id%5D=42'));
});

test('a day Kitsu sends as its midnight UTC stays that day west of Greenwich', function () {
    instanceTimezone('America/New_York');
    seedAnimeIdMap();

    Http::fake([
        'kitsu.app/api/edge/users*' => Http::response(['data' => [['id' => '42', 'type' => 'users']]]),
        'kitsu.app/api/edge/library-entries*filter%5Bkind%5D=anime*' => Http::response(['data' => [
            ['id' => '1', 'type' => 'libraryEntries', 'attributes' => ['status' => 'completed', 'progress' => 1, 'finishedAt' => '2018-03-04T00:00:00.000Z', 'updatedAt' => '2018-03-05T09:00:00.000Z'], 'relationships' => ['anime' => ['data' => ['type' => 'anime', 'id' => '7']]]],
        ], 'included' => [
            ['id' => '7', 'type' => 'anime', 'attributes' => ['canonicalTitle' => 'Princess Mononoke', 'episodeCount' => 1], 'relationships' => ['mappings' => ['data' => [['type' => 'mappings', 'id' => '70']]]]],
            ['id' => '70', 'type' => 'mappings', 'attributes' => ['externalSite' => 'myanimelist/anime', 'externalId' => '164']],
        ], 'links' => []]),
        'kitsu.app/api/edge/library-entries*' => Http::response(['data' => [], 'included' => [], 'links' => []]),
        ...tmdbAnimeFakes(),
    ]);

    ['film' => $film] = animeCatalogue();
    $user = User::factory()->create();

    runListImport($user, 'kitsu', 'someone');

    expect($user->plays()->where('media_item_id', $film->id)->firstOrFail()->watched_at->toDateString())->toBe('2018-03-04');
});

test('a Kitsu name shared by several users asks for the profile number', function () {
    seedAnimeIdMap();

    Http::fake(['kitsu.app/api/edge/users*' => Http::response(['data' => [['id' => '1'], ['id' => '2']]])]);

    $taskRun = runListImport(User::factory()->create(), 'kitsu', 'common');

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toContain('more than one user named common');
});

test('the anime id map is only taken at its pinned revision', function () {
    Storage::fake('local');

    Http::fake(['raw.githubusercontent.com/*' => Http::response('{"1": {"mal_id": 1, "tvdb_id": 2, "tvdb_season": 1}}')]);

    $map = app(AnimeIdMap::class);

    expect($map->isAvailable())->toBeFalse()
        ->and(Storage::disk('local')->files('anime-ids'))->toBe([]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'Kometa-Team/Anime-IDs/'.AnimeIdMap::REVISION.'/anime_ids.json'));
});

test('an older verified map still serves when the pinned one cannot be had', function () {
    Storage::fake('local');
    Storage::disk('local')->put('anime-ids/older.json', '{"1": {"mal_id": 164, "imdb_id": "tt0119698"}}');

    Http::fake(['raw.githubusercontent.com/*' => Http::response('', 503)]);

    expect(app(AnimeIdMap::class)->forMal(164)?->standaloneImdb())->toBe('tt0119698');
});

test('a failed fetch of the pinned map is not tried again by the next process for a while', function () {
    Storage::fake('local');
    Storage::disk('local')->put('anime-ids/older.json', '{"1": {"mal_id": 164, "imdb_id": "tt0119698"}}');
    Http::fake(['raw.githubusercontent.com/*' => Http::response('', 503)]);

    // A fresh instance each time, as a new process would build.
    app()->forgetInstance(AnimeIdMap::class);
    app(AnimeIdMap::class)->forMal(164);
    app()->forgetInstance(AnimeIdMap::class);

    expect(app(AnimeIdMap::class)->forMal(164)?->standaloneImdb())->toBe('tt0119698');
    Http::assertSentCount(2); // the first process's two tries, nothing after them
});

test('a refused account is reported in the user\'s language', function () {
    enableMangaModule();
    seedAnimeIdMap();

    Http::fake(['*/users/nobody/*' => Http::response(['error' => 'not_found'], 404)]);

    $taskRun = runListImport(User::factory()->create(['locale' => 'pl']), 'mal', 'nobody');

    expect($taskRun->summary)->toBe('MyAnimeList nie ma użytkownika o nazwie nobody.');
});

test('a tracker out of reach, or one refusing the request, is said in the user\'s language', function (Closure $answer, string $summary) {
    seedAnimeIdMap();
    Http::fake(['graphql.anilist.co*' => $answer]);

    $taskRun = runListImport(User::factory()->create(['locale' => 'pl']), 'anilist', 'tester');

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe($summary);
})->with([
    'out of reach' => [fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://graphql.anilist.co'), 'AniList jest nieosiągalny: Operation timed out after 15001 milliseconds'],
    'refusing' => [fn () => fn () => Http::response(['errors' => [['message' => 'Forbidden']]], 403), 'AniList odrzucił żądanie (403).'],
]);
