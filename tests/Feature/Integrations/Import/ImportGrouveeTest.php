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

/*
 * Grouvee is exercised on the shape in tests/Fixtures/transfer/grouvee (see
 * the README there).
 */

function grouveeEverything(): ImportSelection
{
    return new ImportSelection(['played', 'playing', 'wanted', 'rated'], ['Metroidvanias']);
}

beforeEach(function () {
    enableGamesModule();
    // A search by name answers Hollow Knight and Silksong.
    Http::fake(['api.igdb.com/v4/games' => Http::response(jsonFixture('igdb/search'))]);
});

test('a Grouvee export offers what its shelves say and the user\'s own shelves', function () {
    $manifest = app(ImportSourceRegistry::class)->get('grouvee')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/grouvee/collection.csv')));

    expect(array_map(fn (ManifestCategory $category) => [$category->key, $category->count], $manifest->categories))->toBe([
        ['played', 2],
        ['playing', 1],
        ['wanted', 1],
        ['rated', 2],
    ])
        ->and(array_map(fn ($list) => [$list->id, $list->itemCount], $manifest->lists))->toBe([['Metroidvanias', 1]]);
});

test('a Grouvee export places games by their IGDB ids and brings over playthroughs, statuses and shelves', function () {
    $user = User::factory()->create();
    $knight = MediaItem::factory()->game()->create(['provider_ref' => '14593']);
    $silksong = MediaItem::factory()->game()->create(['provider_ref' => '115289']);

    $taskRun = runImportFile($user, 'grouvee', putTransferFixture('grouvee', 'collection.csv'), grouveeEverything());

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe([
            'Hollow: no match for an entry without ids, nor by name.',
            'A Grouvee row without a game name.',
        ]);

    // The finished playthrough with its time; the one under way is no play.
    $play = $user->plays()->where('media_item_id', $knight->id)->sole();

    expect($play->watched_at->toDateString())->toBe('2021-09-28')
        ->and((int) $play->amount)->toBe(1820)
        ->and($user->ratings()->where('media_item_id', $knight->id)->value('rating'))->toBe(10)
        ->and($user->lists()->where('title', 'Metroidvanias')->firstOrFail()->items()->pluck('media_item_id')->all())->toBe([$knight->id]);

    $state = $user->mediaStates()->where('media_item_id', $silksong->id)->firstOrFail();

    expect($state->status)->toBe(MediaUserStatus::InProgress)
        ->and($state->progress)->toBe(47);
});

test('importing the same Grouvee export twice adds nothing', function () {
    $user = User::factory()->create();
    MediaItem::factory()->game()->create(['provider_ref' => '14593']);
    MediaItem::factory()->game()->create(['provider_ref' => '115289']);

    runImportFile($user, 'grouvee', putTransferFixture('grouvee', 'collection.csv'), grouveeEverything());
    $again = runImportFile($user, 'grouvee', putTransferFixture('grouvee', 'collection.csv'), grouveeEverything());

    expect($again->summary)->toContain('plays: 0', 'ratings: 0', 'statuses: 0', 'list items: 0');
});

test('a Backloggd list is not a Grouvee export', function () {
    $manifest = app(ImportSourceRegistry::class)->get('grouvee')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/backloggd/toolkittd.csv')));

    expect($manifest->valid)->toBeFalse()
        ->and($manifest->error)->toBe('The file is not a Grouvee export.');
});
