<?php

use App\Actions\Library\DropAttempt;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Library\SyncListMembership;
use App\Catalog\DatePrecision;
use App\Catalog\EpisodeBadge;
use App\Catalog\EpisodeType;
use App\Enums\FollowMode;
use App\Enums\MediaUserStatus;
use App\Enums\ReleaseKind;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\User;
use App\Tracking\Surfaces\CalendarDays;
use Carbon\CarbonImmutable;

function calendarFor(User $user): array
{
    return app(CalendarDays::class)->nearest($user);
}

function watchFirstEpisode(User $user, MediaItem $show): void
{
    app(MarkWatched::class)->execute(
        $user,
        $show->children()->firstOrFail()->children()->where('number', 1)->firstOrFail(),
        WatchedAt::at(CarbonImmutable::parse('2026-01-01')),
    );
}

test('games contribute per-platform releases, including patches and early access', function () {
    enableGamesModule();

    $user = User::factory()->create();

    // Already released worldwide, but two platform premieres are ahead —
    // one of them a next-gen patch (statuses are deliberately unfiltered).
    $game = MediaItem::factory()->game()->create([
        'release_date' => '2020-01-01',
        'metadata' => ['platformReleases' => [
            ['date' => '2020-01-01', 'platform' => 'PC', 'status' => null, 'region' => 'worldwide'],
            // Two regional dates for the Switch premiere — the user's
            // release-region preference decides which one counts.
            ['date' => today()->addDays(10)->toDateString(), 'platform' => 'Switch', 'status' => null, 'region' => 'europe'],
            ['date' => today()->addDays(15)->toDateString(), 'platform' => 'Switch', 'status' => null, 'region' => 'north_america'],
            ['date' => today()->addDays(10)->toDateString(), 'platform' => 'PS5', 'status' => null, 'region' => 'europe'],
            ['date' => today()->addDays(20)->toDateString(), 'platform' => 'Switch 2', 'status' => 'Next-Gen Optimization Patch Release', 'region' => 'worldwide'],
        ]],
    ]);

    app(SyncListMembership::class)->execute($user, $game, [$user->watchlist()->id]);

    $user->preferences = ['region' => 'PL'];
    $user->save();

    $days = calendarFor($user->refresh());

    // Same-day platforms merge into one comma-separated entry; the NA
    // Switch date loses to the preferred European one.
    expect($days)->toHaveCount(2)
        ->and($days[0]->date)->toBe(today()->addDays(10)->toDateString())
        ->and($days[0]->entries)->toHaveCount(1)
        ->and($days[0]->entries[0]->media->platform)->toBe('Switch, PS5')
        ->and($days[1]->entries[0]->media->releaseStatus)->toBe('Next-Gen Optimization Patch Release');

    // A North American country shifts the Switch premiere.
    $user->preferences = ['region' => 'CA'];
    $user->save();

    $days = calendarFor($user->refresh());

    expect($days[0]->entries[0]->media->platform)->toBe('PS5')
        ->and($days[1]->date)->toBe(today()->addDays(15)->toDateString())
        ->and($days[1]->entries[0]->media->platform)->toBe('Switch');

    // With the per-platform preference off only the first worldwide date
    // counts — here it is in the past, so nothing shows.
    $user->preferences = ['calendar_game_platforms' => false];
    $user->save();

    expect(calendarFor($user->refresh()))->toBe([]);
});

test('an upcoming game without platform data falls back to its release date', function () {
    enableGamesModule();

    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create(['release_date' => today()->addDays(7)->toDateString(), 'metadata' => null]);

    // A played (not watchlisted) game is tracked too.
    app(MarkWatched::class)->execute($user, $game, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $days = calendarFor($user);

    expect($days)->toHaveCount(1)
        ->and($days[0]->entries[0]->media->title)->toBe($game->title);
});

test('a release known only to its quarter or year stays off the calendar', function () {
    enableGamesModule();

    $user = User::factory()->create();
    // IGDB stamps "Q4 2026" with the quarter's last day: no day to land on.
    $game = MediaItem::factory()->game()->create([
        'release_date' => '2026-12-31',
        'release_date_precision' => DatePrecision::Quarter,
        'metadata' => ['platformReleases' => [
            ['date' => '2026-12-31', 'platform' => 'PC', 'status' => null, 'region' => 'worldwide', 'precision' => 'quarter'],
            ['date' => today()->addDays(10)->toDateString(), 'platform' => 'PS5', 'status' => 'Early Access', 'region' => 'worldwide', 'precision' => 'day'],
        ]],
    ]);
    $vague = MediaItem::factory()->game()->create(['release_date' => '2026-12-31', 'release_date_precision' => DatePrecision::Year, 'metadata' => null]);
    app(SyncListMembership::class)->execute($user, $game, [$user->watchlist()->id]);
    app(SyncListMembership::class)->execute($user, $vague, [$user->watchlist()->id]);

    $days = calendarFor($user);

    expect($days)->toHaveCount(1)
        ->and($days[0]->entries)->toHaveCount(1)
        ->and($days[0]->entries[0]->media->platform)->toBe('PS5');

    $user->preferences = ['calendar_game_platforms' => false];
    $user->save();

    expect(calendarFor($user->refresh()))->toBe([]);
});

test('the calendar lists the nearest days that actually have releases', function () {
    $user = User::factory()->create();

    // Episodes 40 and 90 days out — far beyond a fixed "next week" window.
    $show = makeShowTree([1 => [
        '2020-01-01',
        today()->addDays(40)->toDateString(),
        today()->addDays(90)->toDateString(),
    ]]);
    watchFirstEpisode($user, $show);

    $days = calendarFor($user);

    expect($days)->toHaveCount(2)
        ->and($days[0]->date)->toBe(today()->addDays(40)->toDateString())
        ->and($days[0]->entries[0]->showTitle)->toBe($show->title)
        ->and($days[0]->entries[0]->media->number)->toBe(2)
        ->and($days[1]->date)->toBe(today()->addDays(90)->toDateString());
});

test('a release today is included and releases share a day column', function () {
    $user = User::factory()->create();

    $show = makeShowTree([1 => ['2020-01-01', today()->toDateString()]]);
    watchFirstEpisode($user, $show);

    $movie = MediaItem::factory()->movie()->create(['release_date' => today()->toDateString()]);
    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);

    $days = calendarFor($user);

    expect($days)->toHaveCount(1)
        ->and($days[0]->date)->toBe(today()->toDateString())
        ->and($days[0]->entries)->toHaveCount(2);
});

test('at most five days are returned and the horizon is one year', function () {
    $user = User::factory()->create();

    $dates = collect(range(1, 6))->map(fn (int $i) => today()->addDays($i * 10)->toDateString());
    $show = makeShowTree([1 => ['2020-01-01', ...$dates->all()]]);
    watchFirstEpisode($user, $show);

    // Beyond the horizon: never shown even though days are free.
    $farMovie = MediaItem::factory()->movie()->create(['release_date' => today()->addDays(400)->toDateString()]);
    app(SyncListMembership::class)->execute($user, $farMovie, [$user->watchlist()->id]);

    $days = calendarFor($user);

    expect($days)->toHaveCount(5)
        ->and(array_column($days, 'date'))->toBe($dates->take(5)->all());
});

test('shows without plays or watchlist membership feed no calendar entries', function () {
    $user = User::factory()->create();

    // A persisted show with no interaction never reaches the calendar.
    makeShowTree([1 => [today()->addDay()->toDateString()]]);

    expect(calendarFor($user))->toBeEmpty();
});

test('dropped shows stay in the calendar by default and can be hidden by preference', function () {
    $user = User::factory()->create();

    $dropped = makeShowTree([1 => ['2020-01-01', today()->addDay()->toDateString()]]);
    watchFirstEpisode($user, $dropped);
    app(DropAttempt::class)->execute($user, $dropped, WatchedAt::releaseDateOrNow());

    expect(calendarFor($user))->toHaveCount(1);

    $user->preferences = ['calendar_dropped' => false];
    $user->save();

    expect(calendarFor($user->refresh()))->toBeEmpty();
});

test('on-hold shows follow their own calendar preference', function () {
    $user = User::factory()->create();

    $onHold = makeShowTree([1 => ['2020-01-01', today()->addDay()->toDateString()]]);
    watchFirstEpisode($user, $onHold);
    app(SetMediaStatus::class)->execute($user, $onHold, MediaUserStatus::OnHold);

    expect(calendarFor($user))->toHaveCount(1);

    $user->preferences = ['calendar_on_hold' => false];
    $user->save();

    expect(calendarFor($user->refresh()))->toBeEmpty();
});

test('episodes of a skipped season leave the calendar', function () {
    $user = User::factory()->create();

    $show = makeShowTree([
        1 => ['2020-01-01'],
        2 => [today()->addDay()->toDateString()],
    ]);
    watchFirstEpisode($user, $show);

    expect(calendarFor($user))->toHaveCount(1);

    app(DropAttempt::class)->execute($user, $show->children()->where('number', 2)->firstOrFail(), WatchedAt::releaseDateOrNow());

    expect(calendarFor($user))->toBeEmpty();
});

test('a watchlisted show contributes upcoming episodes without any plays', function () {
    $user = User::factory()->create();

    $show = makeShowTree([1 => [
        today()->addDays(2)->toDateString(),
        today()->addDays(2)->toDateString(),
    ]]);
    app(SyncListMembership::class)->execute($user, $show, [$user->watchlist()->id]);

    $days = calendarFor($user);

    expect($days)->toHaveCount(1)
        ->and($days[0]->entries)->toHaveCount(2)
        ->and($days[0]->entries[0]->showTitle)->toBe($show->title);
});

test('the watchlist preference removes watchlist releases from the calendar', function () {
    $user = User::factory()->create();

    $show = makeShowTree([1 => [today()->addDays(2)->toDateString()]]);
    app(SyncListMembership::class)->execute($user, $show, [$user->watchlist()->id]);

    $movie = MediaItem::factory()->movie()->create(['release_date' => today()->addDays(3)->toDateString()]);
    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);

    $user->preferences = ['calendar_watchlist' => false];
    $user->save();

    expect(calendarFor($user->refresh()))->toBeEmpty();
});

test('an episode airing for a watched show is not duplicated by the watchlist', function () {
    $user = User::factory()->create();

    $show = makeShowTree([1 => ['2020-01-01', today()->addDay()->toDateString()]]);
    watchFirstEpisode($user, $show);
    app(SyncListMembership::class)->execute($user, $show, [$user->watchlist()->id]);

    $days = calendarFor($user);

    expect($days)->toHaveCount(1)
        ->and($days[0]->entries)->toHaveCount(1);
});

test('watchlist movie releases appear, other movies do not', function () {
    $user = User::factory()->create();

    $listed = MediaItem::factory()->movie()->create(['release_date' => today()->addDays(3)->toDateString()]);
    MediaItem::factory()->movie()->create(['release_date' => today()->addDays(3)->toDateString()]);

    app(SyncListMembership::class)->execute($user, $listed, [$user->watchlist()->id]);

    $days = calendarFor($user);

    expect($days)->toHaveCount(1)
        ->and($days[0]->entries)->toHaveCount(1)
        ->and($days[0]->entries[0]->media->ref)->toBe($listed->provider_ref)
        ->and($days[0]->entries[0]->showTitle)->toBeNull();
});

/** A watchlisted film with both a premiere and a day it leaves cinemas. */
function watchlistFilmWithHomeRelease(User $user, string $mode): MediaItem
{
    $movie = MediaItem::factory()->movie()->create([
        'release_date' => today()->addDays(3)->toDateString(),
        'home_release_date' => today()->addDays(30)->toDateString(),
    ]);

    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);

    $user->preferences = ['calendar_release_mode' => $mode];
    $user->save();

    return $movie;
}

test('a film contributes both its premiere and its home release', function () {
    $user = User::factory()->create();
    watchlistFilmWithHomeRelease($user, 'both');

    $days = calendarFor($user);

    // Two days, two entries — the same title, two different events, which the
    // dedupe key has to keep apart rather than collapse.
    expect($days)->toHaveCount(2)
        ->and($days[0]->date)->toBe(today()->addDays(3)->toDateString())
        ->and($days[0]->entries[0]->media->releaseKind)->toBe(ReleaseKind::Premiere)
        ->and($days[1]->date)->toBe(today()->addDays(30)->toDateString())
        ->and($days[1]->entries[0]->media->releaseKind)->toBe(ReleaseKind::Home)
        // The home entry reports the date it announces, not the row's own.
        ->and($days[1]->entries[0]->media->releaseDate)->toBe(today()->addDays(30)->toDateString());
});

/** A film the user has seen, whose day out of cinemas is still ahead. */
function watchedFilmWithHomeRelease(User $user): MediaItem
{
    $movie = MediaItem::factory()->movie()->create([
        'release_date' => today()->subDays(20)->toDateString(),
        'home_release_date' => today()->addDays(30)->toDateString(),
    ]);

    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    return $movie;
}

test('a film you have seen puts its home release on the calendar', function () {
    $user = User::factory()->create();
    watchedFilmWithHomeRelease($user);

    $days = calendarFor($user);

    // No bell, no watchlist: watching the thing IS the gesture. Only the home
    // release lands — the premiere is twenty days behind us, outside the
    // window the dashboard asks for.
    expect($days)->toHaveCount(1)
        ->and($days[0]->date)->toBe(today()->addDays(30)->toDateString())
        ->and($days[0]->entries[0]->media->releaseKind)->toBe(ReleaseKind::Home);
});

test('the release mode decides which of a watched film dates count', function () {
    $user = User::factory()->create();
    watchedFilmWithHomeRelease($user);

    $user->preferences = ['calendar_release_mode' => 'cinema'];
    $user->save();

    // The matrix answers "which titles", the display option answers "which of
    // their dates" — and someone who only cares about cinema has no use for
    // the day a film he already saw reaches streaming.
    expect(calendarFor($user->refresh()))->toBeEmpty();
});

test('a watched film follows the history row of the tracking matrix', function () {
    $user = User::factory()->create();
    watchedFilmWithHomeRelease($user);

    $user->preferences = ['calendar_active' => false];
    $user->save();

    expect(calendarFor($user->refresh()))->toBeEmpty();
});

test('a paused or abandoned film moves to its own matrix row', function () {
    $user = User::factory()->create();

    $onHold = watchedFilmWithHomeRelease($user);
    app(SetMediaStatus::class)->execute($user, $onHold, MediaUserStatus::OnHold);

    // Still there under the on-hold row, gone once that row is off — the same
    // partition the container track uses.
    expect(calendarFor($user))->toHaveCount(1);

    $user->preferences = ['calendar_on_hold' => false];
    $user->save();

    expect(calendarFor($user->refresh()))->toBeEmpty();
});

test('a muted film stays out however much history it has', function () {
    $user = User::factory()->create();
    $movie = watchedFilmWithHomeRelease($user);

    $user->follows()->create(['media_item_id' => $movie->id, 'mode' => FollowMode::Mute->value]);

    expect(calendarFor($user))->toBeEmpty();
});

test('watching an episode does not put the show on the standalone track', function () {
    $user = User::factory()->create();

    $show = makeShowTree([1 => ['2020-01-01', today()->addDay()->toDateString()]]);
    watchFirstEpisode($user, $show);

    // The show reaches the calendar through its upcoming EPISODE;
    // the standalone track must not also claim it, or "Up next" and the
    // episode grouping would start seeing a film-shaped show.
    $days = calendarFor($user);

    expect($days)->toHaveCount(1)
        ->and($days[0]->entries[0]->media->type)->toBe('episode');
});

test('the home entry carries the provider note, the premiere entry never does', function () {
    $user = User::factory()->create();

    $movie = watchlistFilmWithHomeRelease($user, 'both');
    $movie->update(['metadata' => [...($movie->metadata ?? []), 'homeReleaseNote' => 'Prime Video']]);

    $days = calendarFor($user);

    // The note belongs to the day the film left cinemas — saying "Prime Video"
    // next to a cinema premiere would be a plain lie.
    expect($days[0]->entries[0]->media->extra)->not->toHaveKey('releaseNote')
        ->and($days[1]->entries[0]->media->extra['releaseNote'])->toBe('Prime Video');
});

test('a film whose provider filed no note gets no note key at all', function () {
    $user = User::factory()->create();
    watchlistFilmWithHomeRelease($user, 'both');

    $days = calendarFor($user);

    // Absent rather than null: the card renders the key blindly, and TMDB
    // leaves this field empty far more often than it fills it.
    expect($days[1]->entries[0]->media->releaseKind)->toBe(ReleaseKind::Home)
        ->and($days[1]->entries[0]->media->extra)->not->toHaveKey('releaseNote');
});

test('a film that opens and leaves cinemas the same day is one merged entry', function () {
    $user = User::factory()->create();

    $movie = watchlistFilmWithHomeRelease($user, 'both');
    $movie->update(['home_release_date' => $movie->release_date->toDateString()]);

    $days = calendarFor($user);

    // One day, ONE entry saying both — two cards stacked on a single date read
    // as the calendar duplicating the title.
    expect($days)->toHaveCount(1)
        ->and($days[0]->date)->toBe(today()->addDays(3)->toDateString())
        ->and($days[0]->entries)->toHaveCount(1)
        ->and($days[0]->entries[0]->media->releaseKind)->toBe(ReleaseKind::Both);
});

test('the cinema mode keeps only the premiere', function () {
    $user = User::factory()->create();
    watchlistFilmWithHomeRelease($user, 'cinema');

    $days = calendarFor($user);

    expect($days)->toHaveCount(1)
        ->and($days[0]->date)->toBe(today()->addDays(3)->toDateString())
        ->and($days[0]->entries[0]->media->releaseKind)->toBe(ReleaseKind::Premiere);
});

test('the digital mode keeps only the day the film leaves cinemas', function () {
    $user = User::factory()->create();
    watchlistFilmWithHomeRelease($user, 'digital');

    $days = calendarFor($user);

    expect($days)->toHaveCount(1)
        ->and($days[0]->date)->toBe(today()->addDays(30)->toDateString())
        ->and($days[0]->entries[0]->media->releaseKind)->toBe(ReleaseKind::Home);
});

test('calendar entries carry the premiere and finale markers', function () {
    $user = User::factory()->create();

    $show = makeShowTree([1 => ['2020-01-01', today()->addDays(3)->toDateString()]]);
    watchFirstEpisode($user, $show);

    $upcoming = $show->children()->firstOrFail()->children()->where('number', 2)->firstOrFail();
    $upcoming->update(['metadata' => ['episodeType' => 'finale', 'episodeBadge' => 'series_finale']]);

    $entry = calendarFor($user)[0]->entries[0];

    expect($entry->media->episodeType)->toBe(EpisodeType::Finale)
        ->and($entry->media->episodeBadge)->toBe(EpisodeBadge::SeriesFinale)
        ->and($entry->media->wireExtra()['episodeBadge'])->toBe('series_finale');
});

test('a user tracking nothing has an empty calendar', function () {
    $user = User::factory()->create();

    expect(calendarFor($user))->toBe([])
        ->and(app(CalendarDays::class)->range($user, '2026-10-01', '2026-10-31'))->toBe([]);
});

test("the nearest days start on the viewer's own today", function () {
    // Noon in Greenwich is two in the morning of the next day on Kiritimati.
    $user = User::factory()->create(['preferences' => ['timezone' => 'Pacific/Kiritimati']]);

    foreach (['2026-10-10', '2026-10-11'] as $date) {
        $movie = MediaItem::factory()->movie()->create(['release_date' => $date]);
        app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);
    }

    expect(array_map(fn ($day): string => $day->date, calendarFor($user)))->toBe(['2026-10-11']);
});
