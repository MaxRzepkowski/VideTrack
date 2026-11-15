<?php

use App\Catalog\MediaItemSummaries;
use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Enums\PositionStatus;
use App\Enums\TaskRunStatus;
use App\Library\LibraryStates;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

function runYamtrackImport(User $user, string $csv): TaskRun
{
    Storage::fake('local');
    Storage::disk('local')->put('imports/test.csv', $csv);

    return runImportFile($user, 'yamtrack', 'imports/test.csv');
}

const YAMTRACK_HEADER = 'media_id,source,media_type,title,image,season_number,episode_number,'
    .'score,progress,status,start_date,end_date,notes,created_at,progressed_at';

test('a Yamtrack CSV imports history, ratings and statuses', function () {
    $user = User::factory()->create();

    $movie = MediaItem::factory()->movie()->create(['provider_ref' => '603', 'title' => 'The Matrix']);
    $show = makeShowTree([1 => ['2011-04-17', '2011-04-24']]);
    $showRef = $show->provider_ref;

    $csv = YAMTRACK_HEADER."\n"
        ."\"603\",\"tmdb\",\"movie\",\"The Matrix\",\"img\",\"\",\"\",\"8.5\",\"1\",\"Completed\",\"\",\"2026-01-10 20:00:00+00:00\",\"\",\"2026-01-09 10:00:00+00:00\",\"2026-01-10 20:05:00+00:00\"\n"
        ."\"{$showRef}\",\"tmdb\",\"tv\",\"Some Show\",\"img\",\"\",\"\",\"9.0\",\"2\",\"Dropped\",\"\",\"\",\"\",\"2025-11-02 08:00:00+00:00\",\"\"\n"
        ."\"{$showRef}\",\"tmdb\",\"season\",\"Some Show\",\"img\",\"1\",\"\",\"7.4\",\"2\",\"Completed\",\"\",\"\",\"\",\"\",\"\"\n"
        ."\"{$showRef}\",\"tmdb\",\"episode\",\"Some Show\",\"img\",\"1\",\"1\",\"\",\"\",\"\",\"\",\"2026-01-11 21:00:00+00:00\",\"\",\"\",\"\"\n"
        ."\"{$showRef}\",\"tmdb\",\"episode\",\"Some Show\",\"img\",\"1\",\"2\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\"\n";

    $taskRun = runYamtrackImport($user, $csv);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('Processed 5 entries.', 'plays: 3', 'ratings: 3', 'drops: 1', 'skipped entries: 0');

    // The movie play uses Yamtrack's end_date; the dateless episode falls
    // back to its air date. Yamtrack's progress column (movie: the 0/1
    // flag, show: episode tallies) never leaks into play amounts — only
    // progress-unit types carry counters.
    expect($user->plays()->pluck('watched_at')->map->toDateString()->all())
        ->toContain('2026-01-10', '2026-01-11', '2011-04-24')
        ->and($user->plays()->whereNotNull('amount')->count())->toBe(0);

    $season = MediaItem::query()->where(['type' => 'season', 'parent_id' => $show->id])->firstOrFail();

    expect($user->ratings()->pluck('rating', 'media_item_id')->all())
        ->toBe([$movie->id => 9, $show->id => 9, $season->id => 7]);

    // Dropped is a play entry on the show (attempt model). Yamtrack keeps no
    // date of dropping, only that it came no earlier than the last episode
    // the file lists — never the import day; and so the show stays dropped.
    $drop = $user->plays()->where('outcome', PlayOutcome::Dropped->value)->firstOrFail();

    expect($drop->media_item_id)->toBe($show->id)
        ->and($drop->watched_at->toDateTimeString())->toBe('2026-01-11 21:00:00')
        ->and($user->mediaStates()->count())->toBe(0);

    // Ratings are backdated to Yamtrack's progressed_at, never the import day.
    $movieRating = $user->ratings()->where('media_item_id', $movie->id)->firstOrFail();

    expect($movieRating->created_at?->toDateTimeString())->toBe('2026-01-10 20:05:00');
});

test('a Dropped season row imports no season skip', function () {
    $user = User::factory()->create();

    // The real shape of a Yamtrack export for an abandoned show: the tv row
    // AND its season row both carry Dropped, because Yamtrack has no
    // "skipped season" — both mean "stopped watching".
    $show = makeShowTree([1 => ['2011-04-17', '2011-04-24', '2011-05-01', '2011-05-08']]);
    $showRef = $show->provider_ref;

    $csv = YAMTRACK_HEADER."\n"
        ."\"{$showRef}\",\"tmdb\",\"tv\",\"Some Show\",\"img\",\"\",\"\",\"\",\"2\",\"Dropped\",\"\",\"\",\"\",\"2025-11-02 08:00:00+00:00\",\"\"\n"
        ."\"{$showRef}\",\"tmdb\",\"season\",\"Some Show\",\"img\",\"1\",\"\",\"\",\"2\",\"Dropped\",\"\",\"\",\"\",\"2025-11-02 08:00:00+00:00\",\"\"\n"
        ."\"{$showRef}\",\"tmdb\",\"episode\",\"Some Show\",\"img\",\"1\",\"1\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\"\n"
        ."\"{$showRef}\",\"tmdb\",\"episode\",\"Some Show\",\"img\",\"1\",\"2\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\"\n";

    runYamtrackImport($user, $csv);

    // Only the show carries the drop. A drop on the season node would settle
    // all four episodes and the show would read 4 of 4 watched.
    $drops = $user->plays()->where('outcome', PlayOutcome::Dropped->value)->pluck('media_item_id');

    expect($drops->all())->toBe([$show->id]);

    $state = app(LibraryStates::class)->resolve($user, [MediaItemSummaries::summary($show)])["show:{$showRef}"];

    expect($state->watchedEpisodes)->toBe(2)
        ->and($state->airedEpisodes)->toBe(4)
        ->and($state->watched)->toBeFalse()
        ->and($state->status)->toBe(PositionStatus::Dropped);
});

test('Planning maps to the watchlist and unmappable sources are tallied', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['provider_ref' => '550']);
    $show = makeShowTree([1 => ['2020-01-01']]);
    $showRef = $show->provider_ref;

    // The season Planning row is Yamtrack's auto-bookkeeping for an
    // unreleased future season — it must NOT land on the watchlist.
    $csv = YAMTRACK_HEADER."\n"
        ."\"550\",\"tmdb\",\"movie\",\"Fight Club\",\"img\",\"\",\"\",\"\",\"0\",\"Planning\",\"\",\"\",\"\",\"2025-06-01 12:00:00+00:00\",\"\"\n"
        ."\"{$showRef}\",\"tmdb\",\"season\",\"Some Show\",\"img\",\"1\",\"\",\"\",\"0\",\"Planning\",\"\",\"\",\"\",\"\",\"\"\n"
        ."\"5114\",\"mal\",\"anime\",\"Fullmetal Alchemist\",\"img\",\"\",\"\",\"9.0\",\"64\",\"Completed\",\"\",\"\",\"\",\"\",\"\"\n";

    $taskRun = runYamtrackImport($user, $csv);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('list items: 1', 'skipped entries: 1')
        ->and($taskRun->errors)->toContain('Fullmetal Alchemist: no provider for [mal:anime] on this instance.')
        ->and($user->watchlist()->items()->pluck('media_item_id')->all())->toBe([$movie->id])
        ->and($user->watchlist()->items()->firstOrFail()->created_at?->toDateTimeString())->toBe('2025-06-01 12:00:00')
        ->and($user->plays()->count())->toBe(0);
});

test('a movie marked In progress imports no play', function () {
    $user = User::factory()->create();
    MediaItem::factory()->movie()->create(['provider_ref' => '550']);

    $csv = YAMTRACK_HEADER."\n"
        ."\"550\",\"tmdb\",\"movie\",\"Fight Club\",\"img\",\"\",\"\",\"\",\"0\",\"In progress\",\"\",\"\",\"\",\"\",\"\"\n";

    runYamtrackImport($user, $csv);

    expect($user->plays()->count())->toBe(0);
});

test('game rows import plays, ratings and the playing status', function () {
    enableGamesModule();

    $user = User::factory()->create();
    $beaten = MediaItem::factory()->game()->create(['provider_ref' => '7346', 'title' => 'Breath of the Wild']);
    $playing = MediaItem::factory()->game()->create(['provider_ref' => '14593', 'title' => 'Hollow Knight']);
    $planned = MediaItem::factory()->game()->create(['provider_ref' => '115289', 'title' => 'Silksong']);

    $csv = YAMTRACK_HEADER."\n"
        // Yamtrack tracks game progress in minutes — the play freezes them.
        ."\"7346\",\"igdb\",\"game\",\"Breath of the Wild\",\"img\",\"\",\"\",\"9.5\",\"3120\",\"Completed\",\"\",\"2026-02-01 18:00:00+00:00\",\"\",\"\",\"\"\n"
        ."\"14593\",\"igdb\",\"game\",\"Hollow Knight\",\"img\",\"\",\"\",\"\",\"930\",\"In progress\",\"\",\"\",\"\",\"\",\"\"\n"
        ."\"115289\",\"igdb\",\"game\",\"Silksong\",\"img\",\"\",\"\",\"\",\"0\",\"Planning\",\"\",\"\",\"\",\"\",\"\"\n";

    $taskRun = runYamtrackImport($user, $csv);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('plays: 1', 'ratings: 1', 'statuses: 1', 'list items: 1', 'skipped entries: 0');

    expect($user->plays()->firstOrFail()->media_item_id)->toBe($beaten->id)
        ->and($user->plays()->firstOrFail()->amount)->toBe(3120)
        ->and($user->ratings()->firstOrFail()->rating)->toBe(10)
        ->and($user->mediaStates()->firstOrFail()->media_item_id)->toBe($playing->id)
        ->and($user->mediaStates()->firstOrFail()->status)->toBe(MediaUserStatus::InProgress)
        ->and($user->mediaStates()->firstOrFail()->progress)->toBe(930)
        ->and($user->watchlist()->items()->pluck('media_item_id')->all())->toBe([$planned->id]);
});

test('book rows import through the type registry like games do', function () {
    enableBooksModule();

    $user = User::factory()->create();
    $finished = MediaItem::factory()->book()->create(['provider_ref' => '2459845', 'title' => 'The Hobbit']);
    $reading = MediaItem::factory()->book()->create(['provider_ref' => '312460', 'title' => 'Dune']);

    $csv = YAMTRACK_HEADER."\n"
        ."\"2459845\",\"hardcover\",\"book\",\"The Hobbit\",\"img\",\"\",\"\",\"9.0\",\"320\",\"Completed\",\"\",\"2026-03-01 21:00:00+00:00\",\"\",\"\",\"\"\n"
        ."\"312460\",\"hardcover\",\"book\",\"Dune\",\"img\",\"\",\"\",\"\",\"120\",\"In progress\",\"\",\"\",\"\",\"\",\"\"\n"
        // Books from another catalog (OpenLibrary) skip with a clear cause.
        ."\"OL27448W\",\"openlibrary\",\"book\",\"Some Book\",\"img\",\"\",\"\",\"\",\"0\",\"Completed\",\"\",\"\",\"\",\"\",\"\"\n";

    $taskRun = runYamtrackImport($user, $csv);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('plays: 1', 'ratings: 1', 'statuses: 1', 'skipped entries: 1')
        ->and($taskRun->errors)->toContain('Some Book: no provider for [openlibrary:book] on this instance.');

    // The counters travel too: the finished book keeps its page total on
    // the play, the open one lands on page 120.
    expect($user->plays()->firstOrFail()->media_item_id)->toBe($finished->id)
        ->and($user->plays()->firstOrFail()->amount)->toBe(320)
        ->and($user->mediaStates()->firstOrFail()->media_item_id)->toBe($reading->id)
        ->and($user->mediaStates()->firstOrFail()->status)->toBe(MediaUserStatus::InProgress)
        ->and($user->mediaStates()->firstOrFail()->progress)->toBe(120);
});

test('manga rows import through the type registry; anime rows skip with a cause', function () {
    enableMangaModule();

    $user = User::factory()->create();
    $finished = MediaItem::factory()->manga()->create(['provider_ref' => '2', 'title' => 'Berserk']);
    $reading = MediaItem::factory()->manga()->create(['provider_ref' => '116778', 'title' => 'Chainsaw Man']);

    $csv = YAMTRACK_HEADER."\n"
        ."\"2\",\"mal\",\"manga\",\"Berserk\",\"img\",\"\",\"\",\"10.0\",\"380\",\"Completed\",\"\",\"2026-03-01 21:00:00+00:00\",\"\",\"\",\"\"\n"
        ."\"116778\",\"mal\",\"manga\",\"Chainsaw Man\",\"img\",\"\",\"\",\"\",\"120\",\"In progress\",\"\",\"\",\"\",\"\",\"\"\n"
        // Anime is a MAL source too, but this instance has no anime type.
        ."\"30\",\"mal\",\"anime\",\"Neon Genesis Evangelion\",\"img\",\"\",\"\",\"\",\"26\",\"Completed\",\"\",\"\",\"\",\"\",\"\"\n";

    $taskRun = runYamtrackImport($user, $csv);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('plays: 1', 'ratings: 1', 'statuses: 1', 'skipped entries: 1')
        ->and($taskRun->errors)->toContain('Neon Genesis Evangelion: no provider for [mal:anime] on this instance.');

    // The chapter counters travel: 380 chapters frozen into the finished
    // play, the open read resumes at chapter 120.
    expect($user->plays()->firstOrFail()->media_item_id)->toBe($finished->id)
        ->and($user->plays()->firstOrFail()->amount)->toBe(380)
        ->and($user->mediaStates()->firstOrFail()->media_item_id)->toBe($reading->id)
        ->and($user->mediaStates()->firstOrFail()->status)->toBe(MediaUserStatus::InProgress)
        ->and($user->mediaStates()->firstOrFail()->progress)->toBe(120);
});

test('a re-import backfills counters missing from an earlier import', function () {
    enableMangaModule();

    $user = User::factory()->create();
    $finished = MediaItem::factory()->manga()->create(['provider_ref' => '2', 'title' => 'Berserk']);
    $reading = MediaItem::factory()->manga()->create(['provider_ref' => '116778', 'title' => 'Chainsaw Man']);

    $abandoned = MediaItem::factory()->manga()->create(['provider_ref' => '13', 'title' => 'One Piece']);

    // Rows an earlier import wrote without amounts: the play, the open state and
    // the dropped attempt exist, but without amounts.
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $finished->id, 'watched_at' => '2026-03-01 21:00:00']);
    MediaUserState::factory()->create(['user_id' => $user->id, 'media_item_id' => $reading->id, 'status' => MediaUserStatus::InProgress]);
    Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $abandoned->id,
        'watched_at' => '2026-02-01 10:00:00',
        'outcome' => PlayOutcome::Dropped,
    ]);

    $csv = YAMTRACK_HEADER."\n"
        ."\"2\",\"mal\",\"manga\",\"Berserk\",\"img\",\"\",\"\",\"\",\"380\",\"Completed\",\"\",\"2026-03-01 21:00:00+00:00\",\"\",\"\",\"\"\n"
        ."\"116778\",\"mal\",\"manga\",\"Chainsaw Man\",\"img\",\"\",\"\",\"\",\"120\",\"In progress\",\"\",\"\",\"\",\"\",\"\"\n"
        ."\"13\",\"mal\",\"manga\",\"One Piece\",\"img\",\"\",\"\",\"\",\"250\",\"Dropped\",\"\",\"\",\"\",\"2026-02-01 10:00:00+00:00\",\"\"\n";

    runYamtrackImport($user, $csv);

    // Nothing duplicated, the missing counters filled in — the frozen
    // chapters of the dropped attempt too.
    expect($user->plays()->count())->toBe(2)
        ->and($user->plays()->where('media_item_id', $finished->id)->firstOrFail()->amount)->toBe(380)
        ->and($user->plays()->where('media_item_id', $abandoned->id)->firstOrFail()->amount)->toBe(250)
        ->and($user->mediaStates()->count())->toBe(1)
        ->and($user->mediaStates()->firstOrFail()->progress)->toBe(120);
});

test('game rows are skipped when the games module is not configured', function () {
    $user = User::factory()->create();

    $csv = YAMTRACK_HEADER."\n"
        ."\"7346\",\"igdb\",\"game\",\"Breath of the Wild\",\"img\",\"\",\"\",\"\",\"1\",\"Completed\",\"\",\"\",\"\",\"\",\"\"\n";

    $taskRun = runYamtrackImport($user, $csv);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('skipped entries: 1')
        ->and($taskRun->errors)->toContain('Breath of the Wild: no provider for [igdb:game] on this instance.')
        ->and($user->plays()->count())->toBe(0);
});

test('a file without the Yamtrack columns fails the task run', function () {
    $taskRun = runYamtrackImport(User::factory()->create(), "foo,bar\n1,2\n");

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe('The file is not a Yamtrack CSV export.');
});
