<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Library\StartRewatch;
use App\Catalog\Data\MediaSummary;
use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Jobs\RunImport;
use App\Library\Events\PlayRecorded;
use App\Library\Events\RatingChanged;
use App\Library\LibraryStates;
use App\Library\WatchedAt;
use App\Models\Company;
use App\Models\Favorite;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Person;
use App\Models\Play;
use App\Models\Rating;
use App\Models\TaskRun;
use App\Models\User;
use App\Models\WatchCycle;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * @param  array<string, mixed>  $data
 */
function runVideTrackImport(User $user, array $data): TaskRun
{
    Storage::fake('local');
    Storage::disk('local')->put('imports/test.json', (string) json_encode($data));

    return runImportFile($user, 'videtrack', 'imports/test.json');
}

/**
 * @param  array<string, mixed>  $sections
 * @return array<string, mixed>
 */
function videTrackExportFile(array $sections): array
{
    return [
        'format' => 'videtrack-export',
        'version' => 1,
        'exported_at' => now()->toIso8601String(),
        'username' => 'someone',
        'plays' => [],
        'ratings' => [],
        'favorites' => [],
        'statuses' => [],
        'lists' => [],
        ...$sections,
    ];
}

test('the import page renders', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/import')
        ->assertOk();
});

test('uploading an export queues the import job with a pending task run', function () {
    Queue::fake();
    Storage::fake('local');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/import', [
            'source' => 'videtrack',
            'file' => UploadedFile::fake()->createWithContent('backup.json', '{}'),
        ])
        ->assertRedirect();

    Queue::assertPushed(RunImport::class);

    expect(TaskRun::query()->where('name', 'import-videtrack')->where('user_id', $user->id)->exists())->toBeTrue();
});

test('the upload requires a file and a known source', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('settings.import.store'), ['source' => 'no-such-service'])
        ->assertSessionHasErrors(['source', 'file']);
});

test('a full export round-trips into a fresh account', function () {
    $owner = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $episode = MediaItem::query()->where('type', 'episode')->orderBy('number')->firstOrFail();

    Play::factory()->create(['user_id' => $owner->id, 'media_item_id' => $movie->id, 'watched_at' => '2026-02-01 21:00:00']);
    Play::factory()->create(['user_id' => $owner->id, 'media_item_id' => $episode->id, 'watched_at' => '2026-02-02 21:00:00']);
    Rating::factory()->create([
        'user_id' => $owner->id, 'media_item_id' => $movie->id, 'rating' => 9,
        'created_at' => '2026-02-03 12:00:00', 'updated_at' => '2026-02-03 12:00:00',
    ]);
    Favorite::query()->create([
        'user_id' => $owner->id, 'media_item_id' => $show->id,
        'created_at' => '2026-02-04 08:30:00', 'updated_at' => '2026-02-04 08:30:00',
    ]);
    MediaUserState::factory()->create(['user_id' => $owner->id, 'media_item_id' => $show->id, 'status' => MediaUserStatus::OnHold]);
    projectEveryLibrary();
    $owner->watchlist()->items()->create(['media_item_id' => $movie->id, 'position' => 1]);
    $list = $owner->lists()->create(['title' => 'Best of', 'description' => null, 'is_public' => true]);
    $list->items()->create(['media_item_id' => $episode->id, 'position' => 1]);

    $export = backupOf($owner);

    $target = User::factory()->create();
    $taskRun = runVideTrackImport($target, $export);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('Processed 9 entries.', 'plays: 2', 'ratings: 1', 'favorites: 1', 'statuses: 1', 'list items: 2', 'skipped entries: 0')
        ->and($target->plays()->count())->toBe(2)
        ->and($target->ratings()->firstOrFail()->rating)->toBe(9)
        ->and($target->favorites()->firstOrFail()->media_item_id)->toBe($show->id)
        ->and($target->mediaStates()->firstOrFail()->status)->toBe(MediaUserStatus::OnHold)
        ->and($target->watchlist()->items()->pluck('media_item_id')->all())->toBe([$movie->id])
        ->and($target->lists()->where('title', 'Best of')->firstOrFail()->items()->pluck('media_item_id')->all())->toBe([$episode->id]);

    // rated_at / favorited_at travel through the export, so activity feeds
    // on the new account show the original dates.
    expect($target->ratings()->firstOrFail()->created_at?->toDateTimeString())->toBe('2026-02-03 12:00:00')
        ->and($target->favorites()->firstOrFail()->created_at?->toDateTimeString())->toBe('2026-02-04 08:30:00');

    // No provider calls were needed: every position was already persisted.
    Storage::disk('local')->assertMissing('imports/test.json');
});

test('what the import writes is announced as coming from an import', function () {
    Event::fake([PlayRecorded::class, RatingChanged::class]);

    $owner = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    Play::factory()->create(['user_id' => $owner->id, 'media_item_id' => $movie->id, 'watched_at' => '2026-02-01 21:00:00']);
    Rating::factory()->create(['user_id' => $owner->id, 'media_item_id' => $movie->id, 'rating' => 9]);

    runVideTrackImport(User::factory()->create(), backupOf($owner));

    Event::assertDispatched(PlayRecorded::class, fn (PlayRecorded $e): bool => $e->source->value() === 'import:videtrack');
    Event::assertDispatched(RatingChanged::class, fn (RatingChanged $e): bool => $e->source->value() === 'import:videtrack');
});

test('progress counters round-trip: play amounts and open-attempt positions', function () {
    enableMangaModule();
    enableGamesModule();

    $owner = User::factory()->create();
    $finishedGame = MediaItem::factory()->game()->create();
    $reading = MediaItem::factory()->manga()->create();

    // A completed game with frozen hours, and a manga mid-read at
    // chapter 120 — both counters must survive the trip.
    Play::factory()->create([
        'user_id' => $owner->id,
        'media_item_id' => $finishedGame->id,
        'watched_at' => '2026-02-01 21:00:00',
        'amount' => 3120,
    ]);
    MediaUserState::factory()->create([
        'user_id' => $owner->id,
        'media_item_id' => $reading->id,
        'status' => MediaUserStatus::InProgress,
        'progress' => 120,
    ]);
    projectEveryLibrary();

    $export = backupOf($owner);

    expect($export['plays'][0]['amount'])->toBe(3120)
        ->and($export['statuses'][0]['progress'])->toBe(120);

    $target = User::factory()->create();
    $taskRun = runVideTrackImport($target, $export);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($target->plays()->firstOrFail()->amount)->toBe(3120)
        ->and($target->mediaStates()->firstOrFail()->status)->toBe(MediaUserStatus::InProgress)
        ->and($target->mediaStates()->firstOrFail()->progress)->toBe(120);
});

test('the version one export adds a cycle only to explicit rewatch plays', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01']]);
    $episode = $show->children()->firstOrFail()->children()->firstOrFail();

    Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $episode->id,
        'watched_at' => '2026-01-01 20:00:00',
    ]);
    $cycle = WatchCycle::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $show->id,
        'number' => 2,
        'started_at' => '2026-02-01 20:00:00',
    ]);
    Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $episode->id,
        'watch_cycle_id' => $cycle->id,
        'watched_at' => '2026-02-01 20:00:00',
    ]);
    projectEveryLibrary();

    $export = backupOf($user);

    expect($export['version'])->toBe(1)
        ->and($export['plays'])->toHaveCount(2)
        ->and($export['plays'][0])->not->toHaveKey('cycle')
        ->and($export['plays'][1]['cycle'])->toBe(2);
});

test('rewatch cycles round-trip idempotently while a file without cycles stays in the baseline cycle', function () {
    $owner = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);

    app(MarkWatched::class)->execute($owner, $show, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(StartRewatch::class)->execute($owner, $show);
    // The same item and timestamp in two cycles are two distinct facts.
    app(MarkWatched::class)->execute($owner, $show, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $export = backupOf($owner);
    $target = User::factory()->create();
    $first = runVideTrackImport($target, $export);
    $cycle = WatchCycle::query()->where('user_id', $target->id)->firstOrFail();
    $state = app(LibraryStates::class)->resolve($target, [
        new MediaSummary(type: 'show', ref: $show->provider_ref, provider: $show->provider, title: $show->title),
    ])["show:{$show->provider_ref}"];

    expect($first->status)->toBe(TaskRunStatus::Success)
        ->and($target->plays()->count())->toBe(4)
        ->and($target->plays()->whereNull('watch_cycle_id')->count())->toBe(2)
        ->and($target->plays()->where('watch_cycle_id', $cycle->id)->count())->toBe(2)
        ->and($cycle->number)->toBe(2)
        // When the rerun began, not when its first viewing is dated.
        ->and($cycle->started_at->toDateString())->toBe('2026-10-10')
        ->and($state->cycle)->toBe(2)
        ->and($state->completedCycles)->toBe(2)
        ->and($state->watched)->toBeTrue();

    $second = runVideTrackImport($target, $export);

    expect($second->summary)->toContain('plays: 0')
        ->and($target->plays()->count())->toBe(4)
        ->and(WatchCycle::query()->where('user_id', $target->id)->count())->toBe(1);

    // A file without a cycles section or run numbers.
    $oldExport = $export;
    unset($oldExport['cycles']);
    $oldExport['plays'] = array_values(array_filter(
        $oldExport['plays'],
        fn (array $play): bool => ! isset($play['cycle']),
    ));
    $legacyTarget = User::factory()->create();

    runVideTrackImport($legacyTarget, $oldExport);
    runVideTrackImport($legacyTarget, $oldExport);

    expect($legacyTarget->plays()->count())->toBe(2)
        ->and($legacyTarget->plays()->whereNotNull('watch_cycle_id')->exists())->toBeFalse()
        ->and(WatchCycle::query()->where('user_id', $legacyTarget->id)->exists())->toBeFalse();

    // A file whose plays carry their run's number but which has no cycles
    // section: the run is made by its first viewing, started on its day.
    $numberedOnly = $export;
    unset($numberedOnly['cycles']);
    $numberedTarget = User::factory()->create();

    runVideTrackImport($numberedTarget, $numberedOnly);
    $made = WatchCycle::query()->where('user_id', $numberedTarget->id)->sole();

    expect($made->number)->toBe(2)
        ->and($made->started_at->toDateString())->toBe('2026-01-01')
        ->and($numberedTarget->plays()->where('watch_cycle_id', $made->id)->count())->toBe(2);
});

test('importing never overwrites existing entries and stays idempotent', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    Rating::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'rating' => 5]);
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'watched_at' => '2026-03-01 20:00:00']);

    $file = videTrackExportFile([
        'plays' => [
            ['type' => 'movie', 'provider' => 'tmdb', 'ref' => $movie->provider_ref, 'watched_at' => '2026-03-01T20:00:00+00:00', 'outcome' => 'completed'],
            ['type' => 'movie', 'provider' => 'tmdb', 'ref' => $movie->provider_ref, 'watched_at' => '2026-03-05T20:00:00+00:00', 'outcome' => 'completed'],
        ],
        'ratings' => [
            ['type' => 'movie', 'provider' => 'tmdb', 'ref' => $movie->provider_ref, 'rating' => 9],
        ],
    ]);

    $first = runVideTrackImport($user, $file);

    expect($first->summary)->toContain('plays: 1', 'ratings: 0')
        ->and($user->plays()->count())->toBe(2)
        ->and($user->ratings()->firstOrFail()->rating)->toBe(5);

    $second = runVideTrackImport($user, $file);

    expect($second->summary)->toContain('plays: 0', 'ratings: 0')
        ->and($user->plays()->count())->toBe(2);
});

test('entries without a matching provider are skipped and tallied', function () {
    $user = User::factory()->create();

    $taskRun = runVideTrackImport($user, videTrackExportFile([
        'favorites' => [
            ['type' => 'game', 'provider' => 'igdb', 'ref' => '1942', 'title' => 'The Witcher 3'],
        ],
    ]));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('skipped entries: 1')
        ->and($taskRun->errors)->toContain('The Witcher 3: no provider for [igdb:game] on this instance.')
        ->and($user->favorites()->count())->toBe(0);
});

test('a play without a watched_at is skipped, not dated to the import', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    $taskRun = runVideTrackImport($user, videTrackExportFile([
        'plays' => [
            ['type' => 'movie', 'provider' => 'tmdb', 'ref' => $movie->provider_ref, 'watched_at' => ''],
        ],
    ]));

    expect($taskRun->summary)->toContain('skipped entries: 1')
        ->and($user->plays()->count())->toBe(0);
});

test('a file that is not a VideTrack export fails the task run', function () {
    $user = User::factory()->create();

    $taskRun = runVideTrackImport($user, ['some' => 'thing']);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe('The file is not a VideTrack export.');
});

test('a broken entry is reported by its title or its place in the file, in the user\'s language, and nothing else of it', function () {
    $user = User::factory()->create(['locale' => 'pl']);
    $movie = MediaItem::factory()->movie()->create();

    $run = runVideTrackImport($user, videTrackExportFile([
        'plays' => [
            ['type' => 'movie', 'provider' => 'tmdb', 'ref' => $movie->provider_ref, 'title' => 'Sicario', 'watched_at' => ''],
            ['watched_at' => '2026-03-01T20:00:00+00:00', 'note' => 'a private remark'],
        ],
    ]));

    expect($run->summary)->toContain('pominięte wpisy: 2')
        ->and($run->errors)->toBe(['Sicario: wpis nie ma poprawnej daty obejrzenia.', 'plays #2: brak typu, dostawcy albo ref.']);
});

test('a creator on a list from a provider this instance lacks is skipped, not stored', function () {
    $user = User::factory()->create();

    $taskRun = runVideTrackImport($user, videTrackExportFile([
        'lists' => [[
            'title' => 'Directors',
            'items' => [
                ['type' => 'person', 'provider' => 'nowhere', 'ref' => '42', 'title' => 'Some Director', 'added_at' => null],
                ['type' => 'company', 'kind' => 'network', 'provider' => 'nowhere', 'ref' => '7', 'title' => 'Some Network', 'added_at' => null],
            ],
        ]],
    ]));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('skipped entries: 2')
        ->and(Person::query()->count())->toBe(0)
        ->and(Company::query()->count())->toBe(0);
});

test('a restored entry keeps where it first came in; one from an older file is the backup\'s', function () {
    $owner = User::factory()->create();
    $scrobbled = MediaItem::factory()->movie()->create();
    $unknown = MediaItem::factory()->movie()->create();

    Play::factory()->create(['user_id' => $owner->id, 'media_item_id' => $scrobbled->id, 'watched_at' => '2026-02-01 21:00:00', 'source' => 'scrobble:jellyfin']);
    Play::factory()->create(['user_id' => $owner->id, 'media_item_id' => $unknown->id, 'watched_at' => '2026-02-02 21:00:00']);
    Play::factory()->dropped()->create(['user_id' => $owner->id, 'media_item_id' => MediaItem::factory()->movie()->create()->id, 'watched_at' => '2026-02-03 21:00:00', 'source' => 'api']);
    projectLibrary($owner);

    $export = backupOf($owner);

    expect(collect($export['plays'])->pluck('source')->all())->toBe(['scrobble:jellyfin', null, 'api']);

    // A source this version cannot read restores as the backup's own, too.
    $export['plays'][0]['source'] = 'carrier-pigeon';
    $target = User::factory()->create();
    runVideTrackImport($target, $export);
    expect($target->plays()->orderBy('watched_at')->pluck('source')->all())->toBe(['import:videtrack', 'import:videtrack', 'api']);

    $kept = User::factory()->create();
    runVideTrackImport($kept, backupOf($owner));
    // A dropped entry keeps its own as well.
    expect($kept->plays()->orderBy('watched_at')->pluck('source')->all())->toBe(['scrobble:jellyfin', 'import:videtrack', 'api']);
});
