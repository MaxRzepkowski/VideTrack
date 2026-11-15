<?php

use App\Actions\Library\DropAttempt;
use App\Actions\Library\MarkWatched;
use App\Catalog\DatePrecision;
use App\Enums\FollowMode;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/** A show the user is watching: its first episode aired and is watched, the rest are ahead. */
function watchingShow(User $user, array $seasons, string $title): MediaItem
{
    $show = makeShowTree($seasons);
    $show->update(['title' => $title]);

    $first = MediaItem::query()
        ->where('type', 'episode')
        ->whereIn('parent_id', MediaItem::query()->where('parent_id', $show->id)->pluck('id'))
        ->orderBy('release_date')
        ->firstOrFail();
    app(MarkWatched::class)->execute($user, $first, WatchedAt::at(CarbonImmutable::parse('2026-10-02')));

    return $show;
}

/** A movie on the user's watchlist, premiering on `$date`. */
function watchlistedMovie(User $user, string $date, string $title): MediaItem
{
    $movie = MediaItem::factory()->movie()->create(['title' => $title, 'release_date' => $date]);
    $user->watchlist()->items()->create(['media_item_id' => $movie->id, 'position' => 1]);

    return $movie;
}

/**
 * The countdown entries the page shows, as the browser receives them.
 *
 * @param  array<string, string>  $query
 * @return list<array<string, mixed>>
 */
function countdownsOf(User $user, array $query = []): array
{
    $entries = [];

    test()->actingAs($user)
        ->get(route('countdowns', $query))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$entries): void {
            $entries = $page->toArray()['props']['entries'];
        });

    return $entries;
}

test('a show counts down to its next episode only, a movie to its premiere, soonest first', function () {
    $user = User::factory()->create();
    watchingShow($user, [1 => ['2026-10-01', '2026-10-15', '2026-10-22']], 'Severance');
    watchlistedMovie($user, '2026-10-12', 'Dune');

    $this->actingAs($user)
        ->get(route('countdowns'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Countdowns')
            ->has('entries', 2)
            ->where('entries.0.media.title', 'Dune')
            ->where('entries.1.media.type', 'episode')
            ->where('entries.1.media.releaseDate', '2026-10-15')
            ->where('entries.1.filterType', 'show')
            // Counted per root type, in the registry's order.
            ->where('types', [['type' => 'show', 'count' => 1], ['type' => 'movie', 'count' => 1]])
            ->where('pagination', ['currentPage' => 1, 'totalPages' => 1, 'total' => 2]));
});

test('the type filter, the search and the sort narrow and order the list', function () {
    $user = User::factory()->create();
    watchingShow($user, [1 => ['2026-10-01', '2026-10-15']], 'Severance');
    watchlistedMovie($user, '2026-10-12', 'Dune');
    watchlistedMovie($user, '2026-11-20', 'Arrival');

    $this->actingAs($user)
        ->get(route('countdowns', ['type' => 'movie', 'sort' => 'title']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.media.title', 'Arrival')
            ->where('entries.1.media.title', 'Dune')
            ->has('entries', 2)
            ->where('filters', ['type' => 'movie', 'q' => null, 'sort' => 'title', 'dir' => 'asc']));

    // The show's title finds its episode; a type nothing counts down in is "all".
    $this->actingAs($user)
        ->get(route('countdowns', ['q' => 'sever', 'type' => 'game', 'dir' => 'desc']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.type', 'episode')
            ->where('filters.type', null)
            ->where('filters.dir', 'desc'));
});

test('a page past the end shows the last one', function () {
    $user = User::factory()->create();

    foreach (range(1, 13) as $day) {
        watchlistedMovie($user, sprintf('2026-11-%02d', $day), "Movie {$day}");
    }

    $this->actingAs($user)
        ->get(route('countdowns', ['page' => 9]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.title', 'Movie 13')
            ->where('pagination', ['currentPage' => 2, 'totalPages' => 2, 'total' => 13]));
});

test('countdowns keep their own matrix: a dropped show stays off until asked for, the backlog can be switched off', function () {
    $user = User::factory()->create();
    $show = watchingShow($user, [1 => ['2026-10-01', '2026-10-15']], 'Severance');
    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());
    watchlistedMovie($user, '2026-10-12', 'Dune');

    expect(array_column(array_column(countdownsOf($user), 'media'), 'title'))->toBe(['Dune']);

    $user->preferences = ['countdown_dropped' => true, 'countdown_watchlist' => false];
    $user->save();

    expect(array_column(countdownsOf($user->refresh()), 'filterType'))->toBe(['show']);
});

test('the countdown release mode picks which moment of a film counts', function (string $mode, string $kind, string $date) {
    $user = User::factory()->create(['preferences' => ['countdown_release_mode' => $mode]]);
    $movie = watchlistedMovie($user, '2026-10-13', 'Dune');
    $movie->update(['home_release_date' => '2026-11-09']);

    $entries = countdownsOf($user);

    // One countdown per film: with both moments ahead the nearer one stands.
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['media']['extra']['releaseKind'])->toBe($kind)
        ->and($entries[0]['media']['releaseDate'])->toBe($date);
})->with([
    'both' => ['both', 'premiere', '2026-10-13'],
    'cinema' => ['cinema', 'premiere', '2026-10-13'],
    'digital' => ['digital', 'home', '2026-11-09'],
]);

test('a film whose home release comes first counts down to it', function () {
    $user = User::factory()->create(['preferences' => ['countdown_release_mode' => 'both']]);
    watchlistedMovie($user, '2026-11-09', 'Dune')->update(['home_release_date' => '2026-10-13']);

    $entries = countdownsOf($user);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['media']['extra']['releaseKind'])->toBe('home')
        ->and($entries[0]['media']['releaseDate'])->toBe('2026-10-13');
});

test('a special airing sooner does not hide the next real episode', function () {
    $user = User::factory()->create();
    watchingShow($user, [0 => ['2026-10-12'], 1 => ['2026-10-01', '2026-10-15']], 'Severance');

    $entries = countdownsOf($user);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['media']['releaseDate'])->toBe('2026-10-15')
        ->and($entries[0]['media']['extra']['seasonNumber'])->toBe(1);
});

test('a game counts down to its first date, or to every platform when asked', function () {
    enableGamesModule();
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create([
        'title' => 'Hollow Knight: Silksong',
        'release_date' => '2026-10-20',
        'metadata' => ['platformReleases' => [
            ['date' => '2026-10-20', 'platform' => 'PC', 'status' => null, 'region' => 'worldwide'],
            ['date' => '2026-12-01', 'platform' => 'Switch', 'status' => null, 'region' => 'worldwide'],
        ]],
    ]);
    $user->watchlist()->items()->create(['media_item_id' => $game->id, 'position' => 1]);

    $single = countdownsOf($user);

    expect($single)->toHaveCount(1)
        ->and($single[0]['media']['releaseDate'])->toBe('2026-10-20')
        ->and($single[0]['media']['extra']['platform'] ?? null)->toBeNull();

    $user->preferences = ['countdown_game_platforms' => true];
    $user->save();

    expect(array_map(fn (array $entry): ?string => $entry['media']['extra']['platform'] ?? null, countdownsOf($user->refresh())))->toBe(['PC', 'Switch']);
});

test('a release known only to its month or year counts down by name, after the days it may still be', function () {
    enableGamesModule();
    $user = User::factory()->create();
    $listed = function (string $title, string $date, DatePrecision $precision) use ($user): void {
        $game = MediaItem::factory()->game()->create(['title' => $title, 'release_date' => $date, 'release_date_precision' => $precision, 'metadata' => null]);
        $user->watchlist()->items()->create(['media_item_id' => $game->id, 'position' => 1]);
    };
    // IGDB stamps "2028" with its last day and "October 2026" with its
    // first: the month is still ahead on the 10th.
    $listed('The Witcher IV', '2028-12-31', DatePrecision::Year);
    $listed('Kingdom Come: Salvation', '2028-03-31', DatePrecision::Quarter);
    $listed('Exact in 2028', '2028-12-20', DatePrecision::Day);
    $listed('Arriving this month', '2026-10-01', DatePrecision::Month);
    $listed('Last year', '2025-12-31', DatePrecision::Year);

    $entries = countdownsOf($user);

    expect(array_map(fn (array $entry): array => [$entry['media']['title'], $entry['media']['extra']['releasePrecision'] ?? 'day'], $entries))->toBe([
        ['Arriving this month', 'month'],
        ['Kingdom Come: Salvation', 'quarter'],
        ['Exact in 2028', 'day'],
        ['The Witcher IV', 'year'],
    ]);
});

test('a bell counts a title down without any history, a mute takes a watched one out', function () {
    $user = User::factory()->create();
    $belled = MediaItem::factory()->movie()->create(['title' => 'Dune: Part Three', 'release_date' => '2026-12-18']);
    $user->follows()->create(['media_item_id' => $belled->id, 'mode' => FollowMode::Follow->value]);
    $muted = watchingShow($user, [1 => ['2026-10-01', '2026-10-15']], 'Severance');
    $user->follows()->create(['media_item_id' => $muted->id, 'mode' => FollowMode::Mute->value]);

    expect(array_column(array_column(countdownsOf($user), 'media'), 'title'))->toBe(['Dune: Part Three']);
});

test("countdowns start on the viewer's own today", function () {
    // Noon in Greenwich is two in the morning of the next day on Kiritimati.
    $user = User::factory()->create(['preferences' => ['timezone' => 'Pacific/Kiritimati']]);
    watchlistedMovie($user, '2026-10-10', 'Yesterday there');
    watchlistedMovie($user, '2026-10-11', 'Today there');

    expect(array_map(fn (array $entry): string => $entry['media']['title'], countdownsOf($user)))->toBe(['Today there']);
});
