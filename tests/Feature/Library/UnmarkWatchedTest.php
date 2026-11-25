<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Library\UnmarkWatched;
use App\Enums\MediaUserStatus;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;

function unmarkWatched(): UnmarkWatched
{
    return app(UnmarkWatched::class);
}

test('unmarking a playable deletes all of its plays', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $date) {
        app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse($date)));
    }

    expect(unmarkWatched()->execute($user, $movie, WatchedAt::releaseDate()))->toBe(3)
        ->and(Play::count())->toBe(0);
});

test('unmarking an item also clears that item\'s own leftover open state', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    // On hold with zero plays is a legitimate standalone status; this asserts
    // only that "unmark this item" wipes the item clean (symmetry with marking),
    // not that such a state is invalid in general.
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $movie->id,
        'status' => MediaUserStatus::OnHold,
    ]);

    unmarkWatched()->execute($user, $movie, WatchedAt::releaseDate());

    expect(Play::count())->toBe(0)
        ->and(MediaUserState::count())->toBe(0);
});

test('unmarking a season deletes plays of its episodes only', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08'], 2 => ['2021-01-01']]);
    [$season1, $season2] = $show->children()->get()->all();

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));

    expect(unmarkWatched()->execute($user, $season1, WatchedAt::releaseDate()))->toBe(2)
        ->and(Play::whereIn('media_item_id', $season2->children()->pluck('id'))->count())->toBe(1);
});

test('unmarking a show deletes seasons 1..N and leaves season 0 untouched', function () {
    $user = User::factory()->create();
    $show = makeShowTree([0 => ['2019-12-01'], 1 => ['2020-01-01', '2020-01-08']]);
    $specials = $show->children()->where('number', 0)->firstOrFail();

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));
    app(MarkWatched::class)->execute($user, $specials, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));

    expect(unmarkWatched()->execute($user, $show, WatchedAt::releaseDate()))->toBe(2)
        ->and(Play::count())->toBe(1)
        ->and(Play::whereIn('media_item_id', $specials->children()->pluck('id'))->count())->toBe(1);
});

test('unmarking never touches other users plays', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute($other, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    unmarkWatched()->execute($user, $movie, WatchedAt::releaseDate());

    expect(Play::where('user_id', $other->id)->count())->toBe(1);
});

test('one unmark projects the title once, however many events it announces', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    MediaUserState::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'status' => MediaUserStatus::OnHold]);

    $queries = queriesRunBy(fn () => unmarkWatched()->execute($user, $movie));
    $projections = array_filter($queries, fn (QueryExecuted $query): bool => str_starts_with($query->sql, 'select * from "library_entries"') && str_contains($query->sql, '"root_id" in'));

    // A status cleared and a play removed: two events, one projection.
    expect($projections)->toHaveCount(1);
});
