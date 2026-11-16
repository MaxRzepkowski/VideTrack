<?php

use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('Backloggd is only offered with the games module', function () {
    expect(app(ImportSourceRegistry::class)->has('backloggd'))->toBeFalse();
});

test('a Toolkittd list brings over what Backloggd flags, placing games by their exact title', function () {
    enableGamesModule();
    // Any search answers "Hollow Knight" and "Hollow Knight: Silksong".
    Http::fake(['*/games' => Http::response(jsonFixture('igdb/search'))]);

    $user = User::factory()->create();
    $knight = MediaItem::factory()->game()->create(['provider_ref' => '14593']);
    $silksong = MediaItem::factory()->game()->create(['provider_ref' => '115289']);

    $taskRun = runImportFile($user, 'backloggd', putTransferFixture('backloggd', 'toolkittd.csv'));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('Processed 4 entries.', 'plays: 1', 'ratings: 1', 'favorites: 1', 'statuses: 1', 'skipped entries: 2')
        ->and($taskRun->errors)->toBe([
            // "Hollow" is not "Hollow Knight", however high IGDB ranks it.
            'Hollow: no match for an entry without ids, nor by name.',
            'Some Game: no status, rating or like in the file.',
        ]);

    $play = $user->plays()->where('media_item_id', $knight->id)->firstOrFail();

    expect($play->watched_at->toDateString())->toBe('2024-03-09')
        ->and((int) $play->amount)->toBe(30 * 60 + 15)
        // Half stars as tenths: 9 is four and a half.
        ->and($user->ratings()->where('media_item_id', $knight->id)->value('rating'))->toBe(9)
        ->and($user->favorites()->pluck('media_item_id')->all())->toBe([$knight->id]);

    $state = $user->mediaStates()->where('media_item_id', $silksong->id)->firstOrFail();

    expect($state->status)->toBe(MediaUserStatus::InProgress)
        ->and($state->progress)->toBe(47);
});

test('a BackloggdExporter list brings over ratings in stars', function () {
    enableGamesModule();
    Http::fake(['*/games' => Http::response(jsonFixture('igdb/search'))]);

    $user = User::factory()->create();
    $knight = MediaItem::factory()->game()->create(['provider_ref' => '14593']);
    MediaItem::factory()->game()->create(['provider_ref' => '115289']);

    $taskRun = runImportFile($user, 'backloggd', putTransferFixture('backloggd', 'backloggdexporter.csv'));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('ratings: 1', 'skipped entries: 2')
        ->and($taskRun->errors)->toBe([
            'Hollow Knight: Silksong: no rating, and the file holds nothing else.',
            'Hollow: no match for an entry without ids, nor by name.',
        ])
        ->and($user->ratings()->where('media_item_id', $knight->id)->value('rating'))->toBe(9)
        ->and($user->plays()->count())->toBe(0);
});

test('importing the same Backloggd list twice adds nothing', function () {
    enableGamesModule();
    Http::fake(['*/games' => Http::response(jsonFixture('igdb/search'))]);

    $user = User::factory()->create();
    MediaItem::factory()->game()->create(['provider_ref' => '14593']);
    MediaItem::factory()->game()->create(['provider_ref' => '115289']);

    runImportFile($user, 'backloggd', putTransferFixture('backloggd', 'toolkittd.csv'));
    $again = runImportFile($user, 'backloggd', putTransferFixture('backloggd', 'toolkittd.csv'));

    expect($again->summary)->toContain('plays: 0', 'ratings: 0', 'favorites: 0', 'statuses: 0');
});

test('a HowLongToBeat export is not a Backloggd list', function () {
    enableGamesModule();

    $manifest = app(ImportSourceRegistry::class)->get('backloggd')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/hltb/games.csv')));

    expect($manifest->valid)->toBeFalse()
        ->and($manifest->error)->toBe('The file is not a Backloggd list from Toolkittd or BackloggdExporter.');
});

test('a Backloggd row without a title is reported, not searched for', function () {
    enableGamesModule();
    Http::fake();
    Storage::fake('local');
    Storage::disk('local')->put('imports/backloggd.csv', "Title,Rating\n,4.0\n");

    $taskRun = runImportFile(User::factory()->create(), 'backloggd', 'imports/backloggd.csv');

    expect($taskRun->errors)->toBe(['A Backloggd row without a game title.']);
    Http::assertNothingSent();
});

test('a game named with its edition and trademark signs is placed on the game itself', function () {
    enableGamesModule();
    Http::fake(['*/games' => Http::response(jsonFixture('igdb/search'))]);
    Storage::fake('local');
    Storage::disk('local')->put('imports/backloggd.csv', "Title,Rating\n\"Hollow Knight™ - Game of the Year Edition (PC)\",4.5\n");

    $user = User::factory()->create();
    $knight = MediaItem::factory()->game()->create(['provider_ref' => '14593']);

    $taskRun = runImportFile($user, 'backloggd', 'imports/backloggd.csv');

    expect($taskRun->errors)->toBeEmpty()
        ->and($user->ratings()->where('media_item_id', $knight->id)->value('rating'))->toBe(9);
    // The full name first, then the bare one.
    Http::assertSent(fn ($request) => str_contains($request->body(), 'search "Hollow Knight"'));
});
