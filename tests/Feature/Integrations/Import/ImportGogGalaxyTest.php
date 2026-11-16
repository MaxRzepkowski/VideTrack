<?php

use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * GOG Galaxy is exercised on the shape in tests/Fixtures/transfer/gog-galaxy
 * (see the README there).
 */

beforeEach(function () {
    // More IGDB searches than its rate limiter lets through in a second.
    Sleep::fake(syncWithCarbon: true);
    enableGamesModule();
    // Any search answers Hollow Knight and Silksong.
    Http::fake(['api.igdb.com/v4/games' => Http::response(jsonFixture('igdb/search'))]);
});

test('every game goes on the GOG Galaxy list; play time as in progress waits for the user to tick it', function () {
    $manifest = app(ImportSourceRegistry::class)->get('gog-galaxy')
        ->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/gog-galaxy/gameDB.csv')));

    // The hidden game is left out.
    expect(array_map(fn (ManifestCategory $category) => [$category->key, $category->count, $category->preselected], $manifest->categories))->toBe([
        ['library', 3, true],
        ['played', 2, false],
    ]);
});

test('a GOG Galaxy library comes over as a list with the stars, never as finished games', function () {
    $user = User::factory()->create();
    $knight = MediaItem::factory()->game()->create(['provider_ref' => '14593']);
    $silksong = MediaItem::factory()->game()->create(['provider_ref' => '115289']);

    $taskRun = runImportFile($user, 'gog-galaxy', putTransferFixture('gog-galaxy', 'gameDB.csv'), new ImportSelection(['library']));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe(['Hollow: no match for an entry without ids, nor by name.'])
        ->and($user->lists()->where('title', 'GOG Galaxy')->firstOrFail()->items()->pluck('media_item_id')->all())->toBe([$knight->id, $silksong->id])
        ->and($user->ratings()->where('media_item_id', $knight->id)->value('rating'))->toBe(10)
        ->and($user->plays()->count())->toBe(0)
        ->and($user->mediaStates()->count())->toBe(0);
});

test('played games come over as in progress with their play time when the user asks', function () {
    $user = User::factory()->create();
    MediaItem::factory()->game()->create(['provider_ref' => '14593']);
    $silksong = MediaItem::factory()->game()->create(['provider_ref' => '115289']);

    runImportFile($user, 'gog-galaxy', putTransferFixture('gog-galaxy', 'gameDB.csv'), new ImportSelection(['played']));

    $state = $user->mediaStates()->where('media_item_id', $silksong->id)->firstOrFail();

    expect($state->status)->toBe(MediaUserStatus::InProgress)
        ->and($state->progress)->toBe(47)
        ->and($user->lists()->where('title', 'GOG Galaxy')->exists())->toBeFalse();
});

test('importing the same GOG Galaxy library twice adds nothing', function () {
    $user = User::factory()->create();
    MediaItem::factory()->game()->create(['provider_ref' => '14593']);
    MediaItem::factory()->game()->create(['provider_ref' => '115289']);
    $selection = new ImportSelection(['library', 'played']);

    runImportFile($user, 'gog-galaxy', putTransferFixture('gog-galaxy', 'gameDB.csv'), $selection);
    $again = runImportFile($user, 'gog-galaxy', putTransferFixture('gog-galaxy', 'gameDB.csv'), $selection);

    expect($again->summary)->toContain('ratings: 0', 'statuses: 0', 'list items: 0');
});

test('a file without a title column is not a GOG Galaxy library', function () {
    $manifest = app(ImportSourceRegistry::class)->get('gog-galaxy')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/playnite/library-en.csv')));

    expect($manifest->valid)->toBeFalse()
        ->and($manifest->error)->toBe('The file is not a GOG Galaxy library from GOG-Galaxy-Export-Script: it has no title column.');
});
