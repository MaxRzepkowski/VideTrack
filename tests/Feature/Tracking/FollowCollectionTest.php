<?php

use App\Actions\Library\SetFollow;
use App\Actions\Media\DeleteCollection;
use App\Enums\FollowMode;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;
use App\Tracking\Releases\ReleaseEntry;
use App\Tracking\Releases\ReleaseFeed;
use App\Tracking\Surface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The Matrix saga as TMDB serves it: the collection plus its three parts.
 *
 * @return array<string, mixed>
 */
function matrixHttpFakes(): array
{
    return [
        '*/collection/2344*' => Http::response([
            'id' => 2344,
            'name' => 'The Matrix Collection',
            'overview' => 'The saga of Neo.',
            'backdrop_path' => '/collection-backdrop.jpg',
            'parts' => [
                ['id' => 603, 'title' => 'The Matrix', 'poster_path' => '/m1.jpg', 'release_date' => '1999-03-30'],
                ['id' => 604, 'title' => 'The Matrix Reloaded', 'poster_path' => '/m2.jpg', 'release_date' => '2003-05-15'],
                ['id' => 605, 'title' => 'The Matrix Revolutions', 'poster_path' => '/m3.jpg', 'release_date' => '2003-11-05'],
            ],
        ]),
        '*/movie/603*' => Http::response(['id' => 603, 'title' => 'The Matrix', 'poster_path' => '/m1.jpg', 'release_date' => '1999-03-30']),
        '*/movie/604*' => Http::response(['id' => 604, 'title' => 'The Matrix Reloaded', 'poster_path' => '/m2.jpg', 'release_date' => '2003-05-15']),
        '*/movie/605*' => Http::response(['id' => 605, 'title' => 'The Matrix Revolutions', 'poster_path' => '/m3.jpg', 'release_date' => '2003-11-05']),
    ];
}

/** Materializes the Matrix collection and returns its list. */
function matrixCollection(User $user): MediaList
{
    Http::fake(matrixHttpFakes());

    test()->actingAs($user)->get(route('collections.show', ['provider' => 'tmdb', 'ref' => '2344']));

    return MediaList::query()->collections()->sole();
}

/** Marks the saga's last part as not out yet — only those get a sponsored bell. */
function matrixUpcomingPart(): MediaItem
{
    $part = MediaItem::query()->where('provider_ref', '605')->sole();
    $part->update(['release_date' => today()->addDays(10)]);

    return $part;
}

test('the bell on a collection page follows, mutes and clears', function () {
    $user = User::factory()->create();
    $list = matrixCollection($user);

    $this->actingAs($user)
        ->putJson(route('ui.collections.follow.update', ['provider' => 'tmdb', 'ref' => '2344']), ['mode' => 'follow'])
        ->assertSuccessful();

    expect($user->follows()->where('list_id', $list->id)->sole()->mode)->toBe(FollowMode::Follow);

    $this->actingAs($user)
        ->putJson(route('ui.collections.follow.update', ['provider' => 'tmdb', 'ref' => '2344']), ['mode' => 'mute']);

    expect($user->follows()->where('list_id', $list->id)->sole()->mode)->toBe(FollowMode::Mute);

    // Nothing sponsors a collection row, so clearing it removes it outright.
    $this->actingAs($user)
        ->putJson(route('ui.collections.follow.update', ['provider' => 'tmdb', 'ref' => '2344']), ['mode' => null]);

    expect($user->follows()->where('list_id', $list->id)->exists())->toBeFalse();
});

test('following a collection materializes it when the page was never opened', function () {
    Http::fake(matrixHttpFakes());

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.collections.follow.update', ['provider' => 'tmdb', 'ref' => '2344']), ['mode' => 'follow'])
        ->assertSuccessful();

    $list = MediaList::query()->collections()->sole();

    expect($user->follows()->where('list_id', $list->id)->sole()->mode)->toBe(FollowMode::Follow);
});

test('an unknown collection cannot be followed', function () {
    $this->actingAs(User::factory()->create())
        ->putJson(route('ui.collections.follow.update', ['provider' => 'nope', 'ref' => '1']), ['mode' => 'follow'])
        ->assertNotFound();
});

test('the collection page carries the follow mode of the signed-in user', function () {
    $user = User::factory()->create();
    $list = matrixCollection($user);

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page
            ->where('list.followMode', null)
            // The page addresses the collection by what the server says, never by parsing its key.
            ->where('list.collection', ['provider' => 'tmdb', 'ref' => '2344'])
            ->where('list.displayTitle', 'The Matrix Collection'));

    $this->actingAs($user)->putJson(route('ui.collections.follow.update', ['provider' => 'tmdb', 'ref' => '2344']), ['mode' => 'follow']);

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page->where('list.followMode', 'follow'));

    // Another user's bell never leaks into these props.
    $other = User::factory()->create();

    $this->actingAs($other)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page->where('list.followMode', null));
});

test('a plain list page carries no follow mode and no collection, the backlog its translated title', function () {
    $user = User::factory()->create();
    $list = MediaList::factory()->create(['user_id' => $user->id, 'title' => 'Mine']);

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page->where('list.followMode', null)->where('list.collection', null));

    $this->actingAs($user)
        ->get(listPage($user->watchlist()))
        ->assertInertia(fn (Assert $page) => $page->where('list.displayTitle', 'Backlog'));
});

test('a collection follow targets nothing else', function () {
    $user = User::factory()->create();
    $list = matrixCollection($user);

    followCollection($user, $list, FollowMode::Follow);

    $row = $user->follows()->whereNotNull('list_id')->sole();

    expect($row->list_id)->toBe($list->id)
        ->and($row->media_item_id)->toBeNull()
        ->and($row->person_id)->toBeNull()
        ->and($row->company_id)->toBeNull();
});

test('the database rejects a second bell on the same collection', function () {
    $user = User::factory()->create();
    $list = matrixCollection($user);

    Follow::factory()->create(['user_id' => $user->id, 'list_id' => $list->id, 'mode' => 'follow']);

    expect(fn () => Follow::factory()->create(['user_id' => $user->id, 'list_id' => $list->id, 'mode' => 'mute']))
        ->toThrow(QueryException::class);
});

test('deleting the collection takes its bells with it', function () {
    $user = User::factory()->create();
    $list = matrixCollection($user);

    followCollection($user, $list, FollowMode::Follow);

    // The refresh task deletes a list whose collection vanished upstream —
    // the bell on the saga AND the ones it put on its parts go with it.
    app(DeleteCollection::class)->execute($list);

    expect(Follow::query()->count())->toBe(0);
});

test('a followed collection shows up on the followed page and can be dropped from there', function () {
    $user = User::factory()->create();
    $list = matrixCollection($user);
    matrixUpcomingPart();

    followCollection($user, $list, FollowMode::Follow);

    $this->actingAs($user)
        ->get(route('users.following', ['user' => $user->username]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/UserFollowing')
            // The saga itself, plus the one part still to be released.
            ->has('entries', 2)
            ->where('entries.1.kind', 'collection')
            ->where('entries.1.media.type', 'collection')
            ->where('entries.1.media.ref', '2344')
            ->where('entries.1.media.title', 'The Matrix Collection')
            // The cover is the first part's poster — a collection list has none.
            ->where('entries.1.media.posterPath', '/m1.jpg')
            ->where('entries.1.media.extra.itemCount', 3)
            // The part says which saga put the bell there.
            ->where('entries.0.kind', 'via')
            ->where('entries.0.personName', 'The Matrix Collection'));

    $this->actingAs($user)
        ->putJson(route('ui.collections.follow.update', ['provider' => 'tmdb', 'ref' => '2344']), ['mode' => null]);

    $this->actingAs($user)
        ->get(route('users.following', ['user' => $user->username]))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0));
});

test('following a collection bells the parts still to be released, and only those', function () {
    $user = User::factory()->create();
    $list = matrixCollection($user);
    $upcoming = matrixUpcomingPart();

    followCollection($user, $list, FollowMode::Follow);

    $row = $user->follows()->whereNotNull('media_item_id')->sole();

    // Three parts in the saga, two of them out for twenty years.
    expect($list->items()->count())->toBe(3)
        ->and($row->media_item_id)->toBe($upcoming->id)
        ->and($row->mode)->toBe(FollowMode::Follow)
        // It says WHY it is followed.
        ->and($row->viaLists()->pluck('lists.id')->all())->toBe([$list->id]);
});

test('a saga entirely out gets no part bells at all', function () {
    $user = User::factory()->create();
    $list = matrixCollection($user);

    followCollection($user, $list, FollowMode::Follow);

    // The bell still means something: it is what makes a new part announce
    // itself. It just has nothing to put a countdown on today.
    expect($user->follows()->whereNotNull('media_item_id')->count())->toBe(0)
        ->and($user->follows()->whereNotNull('list_id')->count())->toBe(1);
});

test('unfollowing a collection takes its part bells with it', function () {
    $user = User::factory()->create();
    $list = matrixCollection($user);
    matrixUpcomingPart();

    followCollection($user, $list, FollowMode::Follow);
    followCollection($user, $list, null);

    expect(Follow::query()->count())->toBe(0);
});

test('muting a collection stops it feeding the calendar', function () {
    $user = User::factory()->create();
    $list = matrixCollection($user);
    matrixUpcomingPart();

    followCollection($user, $list, FollowMode::Follow);
    followCollection($user, $list, FollowMode::Mute);

    // The collection row survives (the bell shows muted); the parts do not.
    expect($user->follows()->whereNotNull('list_id')->sole()->mode)->toBe(FollowMode::Mute)
        ->and($user->follows()->whereNotNull('media_item_id')->count())->toBe(0);
});

test('a part bellled by hand survives unfollowing the collection', function () {
    $user = User::factory()->create();
    $list = matrixCollection($user);

    $part = matrixUpcomingPart();

    // The user's own bell, set before the saga was followed: it is theirs.
    app(SetFollow::class)->execute($user, $part, FollowMode::Follow);
    followCollection($user, $list, FollowMode::Follow);
    followCollection($user, $list, null);

    expect($user->follows()->where('media_item_id', $part->id)->sole()->mode)->toBe(FollowMode::Follow);
});

test('a part of two followed collections outlives unfollowing one', function () {
    $user = User::factory()->create();
    $list = matrixCollection($user);

    // A second saga that happens to contain the same movie.
    $other = MediaList::factory()->create(['user_id' => null, 'title' => 'Neo Collection', 'system_key' => 'collection:tmdb:9999']);
    $part = matrixUpcomingPart();
    $other->items()->create(['media_item_id' => $part->id, 'position' => 1]);

    followCollection($user, $list, FollowMode::Follow);
    followCollection($user, $other, FollowMode::Follow);
    followCollection($user, $list, null);

    $row = $user->follows()->where('media_item_id', $part->id)->sole();

    expect($row->viaLists()->pluck('lists.id')->all())->toBe([$other->id]);
});

test('a followed saga reaches the calendar with the collection as the reason', function () {
    $user = User::factory()->create();
    $list = matrixCollection($user);

    // An unreleased part is what a calendar window can actually show.
    matrixUpcomingPart();

    followCollection($user, $list, FollowMode::Follow);

    $entries = app(ReleaseFeed::class)->entries($user, Surface::Calendar, today()->toDateString(), today()->addDays(30)->toDateString());

    $entry = $entries->firstWhere(fn (ReleaseEntry $row) => $row->media->ref === '605');

    expect($entry)->not->toBeNull()
        ->and($entry?->media->extra['followedVia'] ?? null)->toBe('The Matrix Collection');
});

test('a muted collection stays listed as muted', function () {
    $user = User::factory()->create();
    $list = matrixCollection($user);

    followCollection($user, $list, FollowMode::Mute);

    $this->actingAs($user)
        ->get(route('users.following', ['user' => $user->username]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.kind', 'muted')
            ->where('entries.0.media.type', 'collection'));
});
