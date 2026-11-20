<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Media\PlanCatalogRefresh;
use App\Catalog\GameKind;
use App\Catalog\TitleStatus;
use App\Library\WatchedAt;
use App\Models\ChangeCursor;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\Rating;
use App\Models\TaskRun;
use App\Models\TitleRefresh;
use App\Models\User;
use App\Tasks\CatalogRefresh\RefreshQueue;
use App\Tasks\CatalogRefresh\RefreshReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// What the morning's plan marks, without asking any provider.

function plannedReason(MediaItem $root): ?RefreshReason
{
    return TitleRefresh::query()->find($root->id)?->reason;
}

test('a show with history is in the daily set, also once it has ended', function () {
    $show = makeShowTree([1 => ['2020-01-01']]);
    $show->update(['status' => TitleStatus::Ended]);
    app(MarkWatched::class)->execute(User::factory()->create(), episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    app(PlanCatalogRefresh::class)->execute();

    expect(plannedReason($show))->toBe(RefreshReason::Active);
});

test('a show with nothing but a bell on it is in the daily set', function () {
    // No plays, no known episodes ahead: the bell alone keeps it daily.
    $show = makeShowTree([1 => ['2020-01-01']]);
    $show->update(['status' => TitleStatus::Ended]);
    Follow::factory()->create(['media_item_id' => $show->id]);

    app(PlanCatalogRefresh::class)->execute();

    expect(plannedReason($show))->toBe(RefreshReason::Active);
});

test('a released film with a rating is left to the cycle', function () {
    $film = MediaItem::factory()->movie()->create(['status' => TitleStatus::Released, 'release_date' => '2020-05-01']);
    // Another film's turn comes first, so this one is not today's share.
    MediaItem::factory()->movie()->create(['created_at' => now()->subYear()]);
    Rating::factory()->create(['media_item_id' => $film->id]);

    app(PlanCatalogRefresh::class)->execute();

    expect(plannedReason($film))->toBeNull();
});

test('a film with a bell and its home release still ahead is in the daily set', function () {
    $film = MediaItem::factory()->movie()->create([
        'status' => TitleStatus::Released,
        'release_date' => '2026-09-01',
        'home_release_date' => '2026-12-01',
    ]);
    Follow::factory()->create(['media_item_id' => $film->id]);

    app(PlanCatalogRefresh::class)->execute();

    expect(plannedReason($film))->toBe(RefreshReason::Active);
});

test('a film whose kind of title keeps its bell out of the notifications is left to the cycle', function () {
    $film = MediaItem::factory()->movie()->create(['status' => TitleStatus::Released, 'release_date' => '2026-09-01', 'home_release_date' => '2026-12-01']);
    // Another film's turn comes first, so this one is not today's share.
    MediaItem::factory()->movie()->create(['created_at' => now()->subYear()]);
    $user = User::factory()->create(['preferences' => ['type_sources' => ['movie' => ['notifications' => ['backlog']]]]]);
    Follow::factory()->for($user)->create(['media_item_id' => $film->id]);

    app(PlanCatalogRefresh::class)->execute();

    expect(plannedReason($film))->toBeNull();
});

test('an unreleased game with a bell is in the daily set', function () {
    enableGamesModule();
    $game = MediaItem::factory()->game()->create(['release_date' => '2027-03-01', 'status' => null]);
    Follow::factory()->create(['media_item_id' => $game->id]);

    app(PlanCatalogRefresh::class)->execute();

    expect(plannedReason($game))->toBe(RefreshReason::Active);
});

test('a released game with a dlc still to come is in the daily set, one whose dlc are all out is left to the cycle', function () {
    enableGamesModule();
    $dlc = fn (string $date): array => storedRelatedRelease('5000', 'The Master Trials', GameKind::Dlc, $date);
    $awaited = MediaItem::factory()->game()->create(['release_date' => '2017-03-03', 'status' => TitleStatus::Released, 'metadata' => ['relatedReleases' => [$dlc('2027-03-01')]]]);
    $done = MediaItem::factory()->game()->create(['release_date' => '2017-03-03', 'status' => TitleStatus::Released, 'metadata' => ['relatedReleases' => [$dlc('2017-06-30')]]]);
    // Another game's turn comes first, so the one whose DLC are out is not today's share.
    MediaItem::factory()->game()->create(['created_at' => now()->subYear(), 'metadata' => ['relatedReleases' => []]]);

    app(PlanCatalogRefresh::class)->execute();

    expect(plannedReason($awaited))->toBe(RefreshReason::Active)
        ->and(plannedReason($done))->toBeNull();
});

test('a game its provider never listed releases of their own for is in the daily set until it has', function () {
    enableGamesModule();
    $never = MediaItem::factory()->game()->create(['release_date' => '2017-03-03', 'status' => TitleStatus::Released, 'metadata' => null]);
    $none = MediaItem::factory()->game()->create(['release_date' => '2017-03-03', 'status' => TitleStatus::Released, 'metadata' => ['relatedReleases' => []]]);
    // Another game's turn comes first, so the one listed with none is not today's share.
    MediaItem::factory()->game()->create(['created_at' => now()->subYear(), 'metadata' => ['relatedReleases' => []]]);

    app(PlanCatalogRefresh::class)->execute();

    expect(plannedReason($never))->toBe(RefreshReason::Active)
        ->and(plannedReason($none))->toBeNull();
});

test('each day the cycle takes the share of a type that brings every root round within the cycle, longest unasked first', function () {
    // Games: a provider with no list of changes, so the cycle alone watches
    // them, once it has listed what they bring of their own.
    enableGamesModule();
    $count = PlanCatalogRefresh::CYCLE_DAYS * 2 + 1;
    $games = collect(range(1, $count))
        ->map(fn (int $age): MediaItem => MediaItem::factory()->game()->create(['created_at' => now()->subDays($count - $age), 'metadata' => ['relatedReleases' => []]]))
        ->all();
    $share = (int) ceil($count / PlanCatalogRefresh::CYCLE_DAYS);

    $tally = app(PlanCatalogRefresh::class)->execute();

    $firstTurn = TitleRefresh::query()->where('reason', RefreshReason::Cycle)->orderBy('media_item_id')->pluck('media_item_id')->all();

    expect($tally->planned)->toBe([RefreshReason::Cycle->value => $share])
        ->and($firstTurn)->toBe(array_map(fn (MediaItem $game): int => $game->id, array_slice($games, 0, $share)));

    // Asked today, they go to the back of the line.
    foreach ($firstTurn as $id) {
        app(RefreshQueue::class)->recordAsked($id);
    }

    $this->travel(1)->day();
    app(PlanCatalogRefresh::class)->execute();

    $secondTurn = TitleRefresh::query()->where('reason', RefreshReason::Cycle)->orderBy('media_item_id')->pluck('media_item_id')->all();

    expect($secondTurn)->toBe(array_map(fn (MediaItem $game): int => $game->id, array_slice($games, $share, $share)));
});

test('the cycle passes over a root already waiting', function () {
    [$waiting, $next] = [MediaItem::factory()->movie()->create(['created_at' => now()->subYear()]), MediaItem::factory()->movie()->create()];
    app(RefreshQueue::class)->markDue([$waiting->id], RefreshReason::Active);

    app(PlanCatalogRefresh::class)->execute();

    expect(plannedReason($waiting))->toBe(RefreshReason::Active)
        ->and(plannedReason($next))->toBe(RefreshReason::Cycle);
});

/** A film somebody waits for: on the daily set whatever its date. */
function watchlistedFilm(string $ref): MediaItem
{
    $film = MediaItem::factory()->movie()->create(['provider_ref' => $ref]);
    User::factory()->create()->watchlist()->items()->create(['media_item_id' => $film->id, 'position' => 1]);

    return $film;
}

/** A list read before: it answers from yesterday on. */
function listReadYesterday(string $type): void
{
    ChangeCursor::query()->create(['provider' => 'tmdb', 'type' => $type, 'checked_until' => now()->subDay()]);
}

/**
 * @param  list<int>  $movies
 * @param  list<int>  $shows
 */
function fakeChangeLists(array $movies, array $shows = []): void
{
    Http::fake([
        'api.themoviedb.org/3/movie/changes*' => Http::response(['results' => array_map(fn (int $id): array => ['id' => $id], $movies), 'page' => 1, 'total_pages' => 1]),
        'api.themoviedb.org/3/tv/changes*' => Http::response(['results' => array_map(fn (int $id): array => ['id' => $id], $shows), 'page' => 1, 'total_pages' => 1]),
    ]);
}

test('the films a complete list names are marked changed, and the rest of the films leave the daily set', function () {
    $named = watchlistedFilm('603');
    $quiet = watchlistedFilm('11');
    listReadYesterday('movie');
    fakeChangeLists([603]);

    app(PlanCatalogRefresh::class)->execute();

    // The named one waits as changed: the daily set leaves the other films
    // out (the cycle may still reach the other one).
    expect(plannedReason($named))->toBe(RefreshReason::Changed)
        ->and(plannedReason($quiet))->not->toBe(RefreshReason::Active);
});

test('an archive entry stays on the daily set, whatever a list of changes says', function () {
    $gone = watchlistedFilm('603');
    $gone->update(['gone_at' => now()->subWeek()]);
    listReadYesterday('movie');
    fakeChangeLists([]);

    app(PlanCatalogRefresh::class)->execute();

    expect(plannedReason($gone))->toBe(RefreshReason::Active);
});

test('a show a list names is marked, yet shows stay on the daily set: their list is not complete', function () {
    $named = makeShowTree([1 => ['2020-01-01']]);
    $quiet = makeShowTree([1 => ['2020-01-01']]);
    foreach ([$named, $quiet] as $show) {
        app(MarkWatched::class)->execute(User::factory()->create(), episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    }
    listReadYesterday('show');
    fakeChangeLists([], [(int) $named->provider_ref]);

    app(PlanCatalogRefresh::class)->execute();

    // Active outranks changed; the quiet one is asked about all the same.
    expect(plannedReason($named))->toBe(RefreshReason::Active)
        ->and(plannedReason($quiet))->toBe(RefreshReason::Active)
        ->and(TaskRun::query()->count())->toBe(0);
});

test('a list read for the first time cannot tell: films stay on the daily set, and tomorrow it can', function () {
    $film = watchlistedFilm('603');
    Http::fake();

    app(PlanCatalogRefresh::class)->execute();

    expect(plannedReason($film))->toBe(RefreshReason::Active)
        ->and(app(RefreshQueue::class)->checkedUntil('tmdb', 'movie')?->equalTo(now()))->toBeTrue();
    Http::assertNothingSent();
});

test('a list that fails keeps its place, and the films stay on the daily set', function () {
    $film = watchlistedFilm('603');
    listReadYesterday('movie');
    $yesterday = now()->subDay();
    Http::fake([
        'api.themoviedb.org/3/movie/changes*' => Http::response([], 500),
        'api.themoviedb.org/3/tv/changes*' => Http::response(['results' => [], 'page' => 1, 'total_pages' => 1]),
    ]);

    $tally = app(PlanCatalogRefresh::class)->execute();

    expect(plannedReason($film))->toBe(RefreshReason::Active)
        ->and(app(RefreshQueue::class)->checkedUntil('tmdb', 'movie')?->equalTo($yesterday))->toBeTrue()
        ->and($tally->errors)->toHaveCount(1)
        ->and($tally->errors[0])->toContain('changed movie titles at tmdb');
});

test('the marks and the new place in the list are written together', function () {
    $film = watchlistedFilm('603');
    listReadYesterday('movie');
    $yesterday = now()->subDay();
    fakeChangeLists([603]);

    // The write breaks halfway: neither the mark nor the place survives it.
    DB::listen(function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'change_cursors') && str_starts_with(strtolower($query->sql), 'insert')) {
            throw new RuntimeException('The disk is full.');
        }
    });

    expect(fn () => app(PlanCatalogRefresh::class)->execute())->toThrow(RuntimeException::class);

    expect(plannedReason($film))->toBeNull()
        ->and(app(RefreshQueue::class)->checkedUntil('tmdb', 'movie')?->equalTo($yesterday))->toBeTrue();
});

test('films go round the cycle in a month, where a complete list watches them', function () {
    $count = PlanCatalogRefresh::CYCLE_DAYS_WITH_FEED + 1;
    MediaItem::factory()->movie()->count($count)->create();
    listReadYesterday('movie');
    fakeChangeLists([]);

    $tally = app(PlanCatalogRefresh::class)->execute();

    expect($tally->planned)->toBe([RefreshReason::Cycle->value => (int) ceil($count / PlanCatalogRefresh::CYCLE_DAYS_WITH_FEED)]);
});
