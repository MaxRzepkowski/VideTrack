<?php

use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\Import\LibraryList;
use App\Integrations\RetroAchievements\RetroAchievementsClient;
use App\Integrations\RetroAchievements\RetroAchievementsSource;
use App\Jobs\RunImport;
use App\Models\MediaItem;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

/*
 * RetroAchievements is exercised on the shapes of its own documentation and
 * source (fixtures in tests/Fixtures/transfer/retroachievements) — never live.
 */

beforeEach(fn () => Http::preventStrayRequests());

/** A key made up for the run, never a real one. */
function retroAchievementsKey(): string
{
    return 'key-of-alex';
}

/**
 * IGDB, answering a title search with the one game of that exact name, and
 * RetroAchievements with the fixture's games.
 *
 * @param  array<string, int>  $igdb  title => IGDB id
 */
function fakeRetroAchievements(array $igdb, mixed $progress = null): void
{
    Http::fake([
        'retroachievements.org/API/*' => $progress ?? Http::response(jsonFixture('transfer/retroachievements/progress')),
        '*/games' => function (Request $request) use ($igdb) {
            foreach ($igdb as $title => $id) {
                if (str_contains($request->body(), 'search "'.$title.'"')) {
                    return Http::response([['id' => $id, 'name' => $title, 'first_release_date' => 700000000]]);
                }
            }

            return Http::response([]);
        },
    ]);
}

/**
 * @param  list<string>  $categories
 */
function runRetroAchievementsImport(User $user, array $categories = ['finished', 'playing', LibraryList::KEY]): TaskRun
{
    $taskRun = TaskRun::queueTask(ImportSourceRegistry::taskName(RetroAchievementsSource::CODE), $user->id);

    app()->call([
        new RunImport($taskRun, RetroAchievementsSource::CODE, ImportInput::credentials(['username' => 'alex', 'key' => retroAchievementsKey()]), new ImportSelection($categories)),
        'handle',
    ]);

    return $taskRun->refresh();
}

test('RetroAchievements is only offered with the games module', function () {
    expect(app(ImportSourceRegistry::class)->has(RetroAchievementsSource::CODE))->toBeFalse();
});

test('the import page asks for a username and a web API key', function () {
    enableGamesModule();

    $this->actingAs(User::factory()->create())
        ->get(route('settings.import.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources', fn ($sources) => collect($sources)->contains(fn ($source) => $source['code'] === RetroAchievementsSource::CODE
                && collect($source['credentials'])->pluck('key')->all() === ['username', 'key'])));
});

test('a look at the games counts what was finished, what is being played and every game for the list, of those a catalogue can hold', function () {
    enableGamesModule();
    fakeRetroAchievements([]);

    $manifest = app(ImportSourceRegistry::class)->get(RetroAchievementsSource::CODE)
        ->analyze(ImportInput::credentials(['username' => 'alex', 'key' => retroAchievementsKey()]));

    expect(array_map(fn ($category) => [$category->key, $category->count], $manifest->categories))->toBe([['finished', 2], ['playing', 1], [LibraryList::KEY, 4]]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'API_GetUserCompletionProgress.php')
        && $request['u'] === 'alex'
        && $request['y'] === retroAchievementsKey()
        && $request['c'] === (string) RetroAchievementsClient::PAGE_SIZE
        && $request->header('User-Agent') === [config('tracker.http.user_agent')]);
});

test('an answer that is not the games is said so, and a refusal is asked once', function (mixed $answer, string $error, int $requests) {
    enableGamesModule();
    Sleep::fake();
    fakeRetroAchievements([], $answer);

    $manifest = app(ImportSourceRegistry::class)->get(RetroAchievementsSource::CODE)
        ->analyze(ImportInput::credentials(['username' => 'alex', 'key' => retroAchievementsKey()]));

    expect($manifest->valid)->toBeFalse()
        ->and($manifest->error)->toBe($error)
        ->and(requestsTo('API_GetUserCompletionProgress'))->toBe($requests);
})->with([
    'a key it turns down' => [fn () => Http::response(['message' => 'Unauthenticated.'], 401), 'RetroAchievements did not accept this web API key.', 1],
    'a username it does not take' => [fn () => Http::response(['message' => 'The u field is invalid.'], 422), 'RetroAchievements does not take this username.', 1],
    'a pause it asks for' => [fn () => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '30']), 'RetroAchievements asked to wait before more requests. Try again later.', 1],
    'down, after asking again' => [fn () => Http::response([], 503), 'RetroAchievements answered 503; try again later.', 3],
    'no games for the username' => [fn () => Http::response(['Count' => 0, 'Total' => 0, 'Results' => []]), 'RetroAchievements shows no games for this username.', 1],
]);

test('games are placed by their title: an award is a finished play on its day, achievements without one are being played', function () {
    enableGamesModule();
    // Each title is a search, and IGDB's limiter waits on the real clock.
    $this->travelBack();
    fakeRetroAchievements(['The Legend of Zelda: A Link to the Past' => 1026, 'Sonic the Hedgehog' => 1155, 'Castlevania: Dracula X' => 1127]);

    $user = User::factory()->create();
    $zelda = MediaItem::factory()->game()->create(['provider_ref' => '1026']);
    $sonic = MediaItem::factory()->game()->create(['provider_ref' => '1155']);
    $castlevania = MediaItem::factory()->game()->create(['provider_ref' => '1127']);

    $taskRun = runRetroAchievementsImport($user);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe([
            '~Hack~ Knuckles the Echidna in Sonic the Hedgehog (Mega Drive / Genesis): a hack, a homebrew game or an achievement subset, which no game catalogue lists.',
            'Mega Man: Powered Up [Subset - 468 Stages] (PlayStation Portable): a hack, a homebrew game or an achievement subset, which no game catalogue lists.',
            'Tetris (Game Boy): no match for an entry without ids, nor by name.',
        ])
        ->and($user->plays()->where('media_item_id', $zelda->id)->sole()->watched_at->toIso8601ZuluString())->toBe('2025-03-02T21:10:00Z')
        ->and($user->plays()->where('media_item_id', $sonic->id)->sole()->watched_at->toIso8601ZuluString())->toBe('2025-01-09T20:30:00Z')
        ->and($user->mediaStates()->where('media_item_id', $castlevania->id)->sole()->status)->toBe(MediaUserStatus::InProgress)
        ->and($user->plays()->where('media_item_id', $castlevania->id)->exists())->toBeFalse()
        ->and($user->lists()->where('title', RetroAchievementsSource::SERVICE)->sole()->items()->pluck('media_item_id')->sort()->values()->all())
        ->toBe([$zelda->id, $sonic->id, $castlevania->id]);
});

test('only what was ticked comes over', function () {
    enableGamesModule();
    $this->travelBack();
    fakeRetroAchievements(['The Legend of Zelda: A Link to the Past' => 1026, 'Castlevania: Dracula X' => 1127]);

    $user = User::factory()->create();
    MediaItem::factory()->game()->create(['provider_ref' => '1026']);
    $castlevania = MediaItem::factory()->game()->create(['provider_ref' => '1127']);

    runRetroAchievementsImport($user, ['playing']);

    expect($user->plays()->count())->toBe(0)
        ->and($user->mediaStates()->where('media_item_id', $castlevania->id)->sole()->status)->toBe(MediaUserStatus::InProgress)
        ->and($user->lists()->where('title', RetroAchievementsSource::SERVICE)->exists())->toBeFalse();
});

test('reading the same games again adds nothing', function () {
    enableGamesModule();
    $this->travelBack();
    fakeRetroAchievements(['The Legend of Zelda: A Link to the Past' => 1026]);

    $user = User::factory()->create();
    MediaItem::factory()->game()->create(['provider_ref' => '1026']);

    runRetroAchievementsImport($user);
    $again = runRetroAchievementsImport($user);

    expect($again->summary)->toContain('plays: 0', 'list items: 0');
});

test('a player with more games than a page is read page by page', function () {
    enableGamesModule();
    $game = fn (int $id) => ['GameID' => $id, 'Title' => "Game {$id}", 'ConsoleName' => 'NES', 'NumAwarded' => 0, 'NumAwardedHardcore' => 0, 'HighestAwardKind' => null];
    $total = RetroAchievementsClient::PAGE_SIZE + 1;

    fakeRetroAchievements([], fn (Request $request) => Http::response([
        'Count' => $request['o'] === '0' ? RetroAchievementsClient::PAGE_SIZE : 1,
        'Total' => $total,
        'Results' => $request['o'] === '0' ? array_map($game, range(1, RetroAchievementsClient::PAGE_SIZE)) : [$game($total)],
    ]));

    $manifest = app(ImportSourceRegistry::class)->get(RetroAchievementsSource::CODE)
        ->analyze(ImportInput::credentials(['username' => 'alex', 'key' => retroAchievementsKey()]));

    expect($manifest->categories[2]->count)->toBe($total)
        ->and(requestsTo('API_GetUserCompletionProgress'))->toBe(2);
});
