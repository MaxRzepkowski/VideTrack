<?php

use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * Playnite is exercised on the shapes in tests/Fixtures/transfer/playnite
 * (see the README there).
 */

beforeEach(function () {
    // A library asks IGDB more than its rate limiter lets through in one
    // second; the faked sleep moves the frozen clock past each wait.
    Sleep::fake(syncWithCarbon: true);
});

/** IGDB knows Hollow Knight by its Steam app id; any search answers Hollow Knight and Silksong. */
function playniteIgdbFakes(): void
{
    enableGamesModule();
    Http::fake([
        'api.igdb.com/v4/external_games' => fn (Request $request) => Http::response(str_contains($request->body(), '"367520"') ? [['id' => 1, 'game' => 14593]] : []),
        'api.igdb.com/v4/games' => Http::response(jsonFixture('igdb/search')),
    ]);
}

/** @return array{0: User, 1: MediaItem, 2: MediaItem} */
function playniteLibrary(): array
{
    return [
        User::factory()->create(),
        MediaItem::factory()->game()->create(['provider_ref' => '14593']),
        MediaItem::factory()->game()->create(['provider_ref' => '115289']),
    ];
}

test('Playnite is only offered with the games module', function () {
    expect(app(ImportSourceRegistry::class)->has('playnite'))->toBeFalse();
});

test('the selection names each completion status with what it does, unplayed games and unknown statuses left for the user to tick', function () {
    enableGamesModule();

    $manifest = app(ImportSourceRegistry::class)->get('playnite')
        ->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/playnite/library-en.csv')));

    expect($manifest->valid)->toBeTrue()
        // The hidden game is left out.
        ->and(array_map(fn (ManifestCategory $category) => [$category->key, $category->label, $category->count, $category->preselected], $manifest->categories))->toBe([
            ['status:Beaten', 'Beaten: played, with the play time', 1, true],
            ['status:Completed', 'Completed: played, with the play time', 1, true],
            ['status:Plan to Play', 'Plan to Play: on the Backlog', 1, true],
            ['status:Playing', 'Playing: in progress', 1, true],
            ['status:Not Played', 'Not Played: only on the Playnite list', 1, false],
            ['status:Replaying', 'Replaying: only on the Playnite list', 1, false],
        ]);
});

test('a Playnite library brings over plays, play time, statuses, scores and favorites', function () {
    playniteIgdbFakes();
    [$user, $knight, $silksong] = playniteLibrary();

    $taskRun = runImportFile($user, 'playnite', putTransferFixture('playnite', 'library-en.csv'), new ImportSelection([
        'status:Beaten', 'status:Completed', 'status:Playing', 'status:Plan to Play', 'status:Not Played', 'status:Replaying',
    ]));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('plays: 1', 'ratings: 1', 'favorites: 1', 'statuses: 1')
        ->and($taskRun->errors)->toBe([
            'Hollow: no match for an entry without ids, nor by name.',
            'Celeste: no match for an entry without ids, nor by name.',
            'Tunic: no match for an entry without ids, nor by name.',
            'A Playnite row without a game name.',
        ]);

    $play = $user->plays()->where('media_item_id', $knight->id)->firstOrFail();

    // The day of the last launch, written the American way; the total to the nearest minute.
    expect($play->watched_at->toDateString())->toBe('2024-03-09')
        ->and((int) $play->amount)->toBe(1820)
        ->and($user->ratings()->where('media_item_id', $knight->id)->value('rating'))->toBe(9)
        ->and($user->favorites()->pluck('media_item_id')->all())->toBe([$knight->id]);

    $state = $user->mediaStates()->where('media_item_id', $silksong->id)->firstOrFail();

    expect($state->status)->toBe(MediaUserStatus::InProgress)
        ->and($state->progress)->toBe(47);
});

test('a Playnite library exported in Polish reads the same, its dates day first', function () {
    playniteIgdbFakes();
    [$user, $knight] = playniteLibrary();

    $taskRun = runImportFile($user, 'playnite', putTransferFixture('playnite', 'library-pl.csv'), new ImportSelection(['status:Beaten']));

    expect($taskRun->errors)->toBeEmpty()
        ->and($user->plays()->where('media_item_id', $knight->id)->value('watched_at')?->toDateString())->toBe('2024-03-09');
});

test('unplayed games go on the Playnite list when the user ticks them', function () {
    playniteIgdbFakes();
    [$user, , $silksong] = playniteLibrary();
    Storage::fake('local');
    Storage::disk('local')->put('imports/playnite.csv', "Name,Completion Status,Added\r\nHollow Knight: Silksong,Not Played,16.11.2026 10:00:00\r\n");

    runImportFile($user, 'playnite', 'imports/playnite.csv', new ImportSelection(['status:Not Played']));

    $list = $user->lists()->where('title', 'Playnite')->firstOrFail();

    expect($list->items()->pluck('media_item_id')->all())->toBe([$silksong->id])
        ->and($user->plays()->count())->toBe(0);
});

test('importing the same Playnite library twice adds nothing', function () {
    playniteIgdbFakes();
    [$user] = playniteLibrary();
    $selection = new ImportSelection(['status:Beaten', 'status:Playing']);

    runImportFile($user, 'playnite', putTransferFixture('playnite', 'library-en.csv'), $selection);
    $again = runImportFile($user, 'playnite', putTransferFixture('playnite', 'library-en.csv'), $selection);

    expect($again->summary)->toContain('plays: 0', 'ratings: 0', 'favorites: 0', 'statuses: 0');
});

test('a file without a name column is not a Playnite library', function () {
    enableGamesModule();

    $manifest = app(ImportSourceRegistry::class)->get('playnite')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/hltb/games.csv')));

    expect($manifest->valid)->toBeFalse()
        ->and($manifest->error)->toBe('The file is not a Playnite library from Library Exporter Advanced: it has no Name column.');
});
