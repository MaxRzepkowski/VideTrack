<?php

use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('HowLongToBeat is only offered with IGDB', function () {
    expect(app(ImportSourceRegistry::class)->has('hltb'))->toBeFalse();
});

test('a HowLongToBeat export places games by their exact title', function () {
    enableGamesModule();
    // Any search answers "Hollow Knight" and "Hollow Knight: Silksong".
    Http::fake(['*/games' => Http::response(jsonFixture('igdb/search'))]);

    Storage::fake('local');
    Storage::disk('local')->put('imports/hltb.csv', (string) file_get_contents(base_path('tests/Fixtures/transfer/hltb/games.csv')));

    $user = User::factory()->create();
    $knight = MediaItem::factory()->game()->create(['provider_ref' => '14593']);
    $silksong = MediaItem::factory()->game()->create(['provider_ref' => '115289']);

    $taskRun = runImportFile($user, 'hltb', 'imports/hltb.csv');

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('Processed 5 entries.', 'plays: 2', 'ratings: 1', 'statuses: 1', 'skipped entries: 2', '2 titles matched by name, not by id.')
        ->and($taskRun->errors)->toBe([
            // "Hollow" is not "Hollow Knight", however high IGDB ranks it.
            'Hollow (PC): no match for an entry without ids, nor by name.',
            'Only In A Custom List (PC): no status is marked in the export.',
        ]);

    // Two playthroughs are two plays, each with its own time.
    $plays = $user->plays()->where('media_item_id', $knight->id)->orderBy('watched_at')->get();

    expect($plays->map(fn ($play) => [$play->watched_at->toDateString(), (int) $play->amount])->all())
        ->toBe([['2024-03-09', 1815], ['2025-01-20', 1200]])
        ->and($user->ratings()->where('media_item_id', $knight->id)->value('rating'))->toBe(9);

    $state = $user->mediaStates()->where('media_item_id', $silksong->id)->firstOrFail();

    expect($state->status)->toBe(MediaUserStatus::InProgress)
        // 46:30 is 46.5 minutes.
        ->and($state->progress)->toBe(47);
});
