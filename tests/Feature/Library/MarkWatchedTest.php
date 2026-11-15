<?php

use App\Actions\Library\DropAttempt;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Library\UnmarkWatched;
use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Exceptions\NoReleaseDate;
use App\Library\AttemptPolicy;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;
use Carbon\CarbonImmutable;

function markWatched(): MarkWatched
{
    return app(MarkWatched::class);
}

test('marking a movie creates one play with the chosen timestamp', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    $when = CarbonImmutable::parse('2026-07-01 21:30');

    $created = markWatched()->execute($user, $movie, WatchedAt::at($when));

    expect($created)->toBe(1)
        ->and(Play::count())->toBe(1)
        ->and(Play::first()->watched_at->toDateTimeString())->toBe('2026-07-01 21:30:00');
});

test('marking an already watched playable adds another play (rewatch)', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    markWatched()->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    markWatched()->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-07-01')));

    expect(Play::where('media_item_id', $movie->id)->count())->toBe(2);
});

test('release date mode stamps a playable with its own release date', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['release_date' => '1999-03-31']);

    markWatched()->execute($user, $movie, WatchedAt::releaseDate());

    expect(Play::first()->watched_at->toDateString())->toBe('1999-03-31');
});

test('release date mode fails for a playable without a release date', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['release_date' => null]);

    markWatched()->execute($user, $movie, WatchedAt::releaseDate());
})->throws(NoReleaseDate::class);

test('marking a season fills only unwatched aired episodes', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08', '2030-01-01', null]]);
    $season = $show->children()->firstOrFail();
    $watched = $season->children()->where('number', 1)->firstOrFail();

    markWatched()->execute($user, $watched, WatchedAt::at(CarbonImmutable::parse('2025-05-05')));

    $created = markWatched()->execute($user, $season, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));

    expect($created)->toBe(1)
        ->and(Play::where('media_item_id', $watched->id)->count())->toBe(1)
        ->and(Play::count())->toBe(2);
});

test('re-marking a fully watched season creates no plays', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $season = $show->children()->firstOrFail();

    markWatched()->execute($user, $season, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));

    expect(markWatched()->execute($user, $season, WatchedAt::at(CarbonImmutable::parse('2026-07-12'))))->toBe(0)
        ->and(Play::count())->toBe(2);
});

test('release date mode stamps each episode with its own air date', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $season = $show->children()->firstOrFail();

    markWatched()->execute($user, $season, WatchedAt::releaseDate());

    expect(Play::query()->orderBy('watched_at')->pluck('watched_at')->map->toDateString()->all())
        ->toBe(['2020-01-01', '2020-01-08']);
});

test('marking a show fills seasons 1..N and skips season 0', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        0 => ['2019-12-01'],
        1 => ['2020-01-01', '2020-01-08'],
        2 => ['2021-01-01', '2030-01-01'],
    ]);

    $created = markWatched()->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));

    $specials = $show->children()->where('number', 0)->firstOrFail();

    expect($created)->toBe(3)
        ->and(Play::whereIn('media_item_id', $specials->children()->pluck('id'))->count())->toBe(0);
});

test('logging a play resumes a dropped or on-hold show', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08'], 2 => ['2021-01-01']]);
    $episode = $show->children()->where('number', 1)->firstOrFail()->children()->firstOrFail();
    $season2 = $show->children()->where('number', 2)->firstOrFail();

    // The drop is a closed attempt on the root; watching anything reopens
    // it, so the dropped play disappears (replaced in place, not a second fact).
    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());
    markWatched()->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));

    expect(Play::query()->where('outcome', PlayOutcome::Dropped->value)->count())->toBe(0);

    app(SetMediaStatus::class)->execute($user, $show, MediaUserStatus::OnHold);
    markWatched()->execute($user, $season2, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));

    expect(MediaUserState::count())->toBe(0);
});

test('completing a playable with a latest dropped attempt replaces it in place', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(DropAttempt::class)->execute($user, $movie, WatchedAt::releaseDateOrNow());
    markWatched()->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));

    expect(Play::count())->toBe(1)
        ->and(Play::first()->outcome)->toBe(PlayOutcome::Completed);

    // A deliberate new attempt keeps the dropped one as its own entry
    // (seeded directly — reachable through the UI for progress types).
    Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $movie->id,
        'watched_at' => CarbonImmutable::parse('2026-07-11 12:00'),
        'outcome' => PlayOutcome::Dropped,
    ]);

    expect(Play::count())->toBe(2);

    markWatched()->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-07-12')), null, AttemptPolicy::NewAttempt);

    expect(Play::count())->toBe(3)
        ->and(Play::query()->where('outcome', PlayOutcome::Dropped->value)->count())->toBe(1);
});

test('a skipped episode and a skipped season stay settled when the show is marked watched', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08'], 2 => ['2021-01-01']]);
    $skippedEpisode = $show->children()->where('number', 1)->firstOrFail()->children()->firstOrFail();
    $season2 = $show->children()->where('number', 2)->firstOrFail();

    app(DropAttempt::class)->execute($user, $skippedEpisode, WatchedAt::releaseDateOrNow());
    app(DropAttempt::class)->execute($user, $season2, WatchedAt::releaseDateOrNow());

    markWatched()->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));

    expect(Play::query()->where('media_item_id', $skippedEpisode->id)->where('outcome', PlayOutcome::Dropped->value)->count())->toBe(1)
        ->and(Play::query()->whereIn('media_item_id', $season2->children()->pluck('id'))->count())->toBe(0)
        ->and(Play::query()->where('media_item_id', $season2->id)->where('outcome', PlayOutcome::Dropped->value)->count())->toBe(1);
});

test('unmarking a show clears skipped seasons and episodes too', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08'], 2 => ['2021-01-01']]);
    $episode = $show->children()->where('number', 1)->firstOrFail()->children()->firstOrFail();
    $season2 = $show->children()->where('number', 2)->firstOrFail();

    markWatched()->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));
    app(DropAttempt::class)->execute($user, $season2, WatchedAt::releaseDateOrNow());

    app(UnmarkWatched::class)->execute($user, $show);

    // Nothing survives — a lingering season drop would keep the show
    // "in progress" forever after a full reset.
    expect(Play::count())->toBe(0);
});

test('watching a skipped season to the end lifts its drop', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $season = $show->children()->firstOrFail();
    [$first, $second] = $season->children()->orderBy('number')->get();

    app(DropAttempt::class)->execute($user, $season, WatchedAt::releaseDateOrNow());

    // Cherry-picking one episode does not resume the season…
    markWatched()->execute($user, $first, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));

    expect(Play::query()->where('media_item_id', $season->id)->where('outcome', PlayOutcome::Dropped->value)->count())->toBe(1);

    // …but completing the last one does: fully watched cannot stay dropped.
    markWatched()->execute($user, $second, WatchedAt::at(CarbonImmutable::parse('2026-07-12')));

    expect(Play::query()->where('media_item_id', $season->id)->count())->toBe(0);
});

test('a no-op cascade on a fully watched season keeps the status', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01']]);
    $season = $show->children()->firstOrFail();

    markWatched()->execute($user, $season, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));
    app(SetMediaStatus::class)->execute($user, $show, MediaUserStatus::OnHold);

    markWatched()->execute($user, $season, WatchedAt::at(CarbonImmutable::parse('2026-07-12')));

    expect(MediaUserState::count())->toBe(1);
});

test('marking season 0 explicitly creates plays for its episodes', function () {
    $user = User::factory()->create();
    $show = makeShowTree([0 => ['2019-12-01', '2019-12-08'], 1 => ['2020-01-01']]);
    $specials = $show->children()->where('number', 0)->firstOrFail();

    $created = markWatched()->execute($user, $specials, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));

    expect($created)->toBe(2)
        ->and(Play::count())->toBe(2);
});
