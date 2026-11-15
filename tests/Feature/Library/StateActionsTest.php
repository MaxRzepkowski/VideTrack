<?php

use App\Actions\Library\CreateList;
use App\Actions\Library\DropAttempt;
use App\Actions\Library\RateItem;
use App\Actions\Library\RemoveRating;
use App\Actions\Library\SetFavorite;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Library\SyncListMembership;
use App\Actions\Library\UndoDrop;
use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Library\WatchedAt;
use App\Models\Favorite;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;

test('rating a position keeps a single current row', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(RateItem::class)->execute($user, $movie, 7);
    app(RateItem::class)->execute($user, $movie, 9);

    expect(Rating::count())->toBe(1)
        ->and(Rating::first()->rating)->toBe(9);

    app(RemoveRating::class)->execute($user, $movie);

    expect(Rating::count())->toBe(0);
});

test('favorite state is idempotent in both directions', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(SetFavorite::class)->execute($user, $movie, true);
    app(SetFavorite::class)->execute($user, $movie, true);

    expect(Favorite::count())->toBe(1);

    app(SetFavorite::class)->execute($user, $movie, false);
    app(SetFavorite::class)->execute($user, $movie, false);

    expect(Favorite::count())->toBe(0);
});

test('dropping closes the attempt as a play entry; on hold is an open state', function () {
    $user = User::factory()->create();
    $show = MediaItem::factory()->show()->create();

    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());

    expect(Play::count())->toBe(1)
        ->and(Play::first()->outcome)->toBe(PlayOutcome::Dropped)
        ->and(MediaUserState::count())->toBe(0);

    // Dropping again with nothing new since is idempotent.
    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());

    expect(Play::count())->toBe(1);

    app(UndoDrop::class)->execute($user, $show);
    app(SetMediaStatus::class)->execute($user, $show, MediaUserStatus::OnHold);

    expect(Play::count())->toBe(0)
        ->and(MediaUserState::first()->status)->toBe(MediaUserStatus::OnHold);

    app(SetMediaStatus::class)->execute($user, $show, null);

    expect(MediaUserState::count())->toBe(0);
});

test('a new account has exactly one watchlist, the same one every time it is asked for', function () {
    $user = User::factory()->create();

    $first = $user->watchlist();
    $second = $user->watchlist();

    expect($first->id)->toBe($second->id)
        ->and($first->system_key)->toBe(MediaList::SYSTEM_WATCHLIST)
        ->and(MediaList::count())->toBe(1);
});

test('list membership sync adds at the end and removes deselected lists', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    $earlier = MediaItem::factory()->movie()->create();

    $watchlist = $user->watchlist();
    $custom = app(CreateList::class)->execute($user, 'Sci-fi');

    app(SyncListMembership::class)->execute($user, $earlier, [$custom->id]);
    app(SyncListMembership::class)->execute($user, $movie, [$watchlist->id, $custom->id]);

    expect(ListItem::where('list_id', $custom->id)->where('media_item_id', $movie->id)->first()->position)->toBe(2);

    app(SyncListMembership::class)->execute($user, $movie, [$custom->id]);

    expect(ListItem::where('list_id', $watchlist->id)->count())->toBe(0)
        ->and(ListItem::where('list_id', $custom->id)->count())->toBe(2);
});

test('list membership sync ignores other users lists', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    $foreign = app(CreateList::class)->execute($other, 'Not mine');

    app(SyncListMembership::class)->execute($user, $movie, [$foreign->id]);

    expect(ListItem::count())->toBe(0);
});
