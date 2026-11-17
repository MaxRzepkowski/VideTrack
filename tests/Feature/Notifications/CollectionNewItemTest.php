<?php

use App\Actions\Media\NotifyCollectionAdditions;
use App\Enums\FollowMode;
use App\Jobs\RefreshCollections;
use App\Library\Data\CollectionSyncResult;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The Matrix saga at TMDB, optionally grown by a fourth part on the second
 * fetch — materialization first, then whatever the refresh task sees.
 *
 * @param  list<array<string, mixed>>  $second
 * @return array<string, mixed>
 */
function matrixSequence(array $second): array
{
    $parts = [
        ['id' => 603, 'title' => 'The Matrix', 'poster_path' => '/m1.jpg', 'release_date' => '1999-03-30'],
        ['id' => 604, 'title' => 'The Matrix Reloaded', 'poster_path' => '/m2.jpg', 'release_date' => '2003-05-15'],
    ];

    $collection = fn (array $with) => [
        'id' => 2344,
        'name' => 'The Matrix Collection',
        'overview' => 'The saga of Neo.',
        'backdrop_path' => '/collection-backdrop.jpg',
        'parts' => $with,
    ];

    return [
        '*/collection/2344*' => Http::sequence()
            ->push($collection($parts))
            ->push($collection($second))
            ->whenEmpty(Http::response($collection($second))),
        '*/movie/603*' => Http::response($parts[0]),
        '*/movie/604*' => Http::response($parts[1]),
        '*/movie/624860*' => Http::response(['id' => 624860, 'title' => 'The Matrix Resurrections', 'poster_path' => '/m4.jpg', 'release_date' => '2027-12-22']),
    ];
}

/** The three original parts plus Resurrections. */
function matrixGrown(): array
{
    return [
        ['id' => 603, 'title' => 'The Matrix', 'poster_path' => '/m1.jpg', 'release_date' => '1999-03-30'],
        ['id' => 604, 'title' => 'The Matrix Reloaded', 'poster_path' => '/m2.jpg', 'release_date' => '2003-05-15'],
        ['id' => 624860, 'title' => 'The Matrix Resurrections', 'poster_path' => '/m4.jpg', 'release_date' => '2027-12-22'],
    ];
}

/** Materializes the saga for $user and returns its list. */
function openMatrix(User $user): MediaList
{
    test()->actingAs($user)->get(route('collections.show', ['provider' => 'tmdb', 'ref' => '2344']));

    return MediaList::query()->collections()->sole();
}

/** The weekly task, run against provider responses that outlived the cache. */
function runRefresh(): void
{
    Cache::flush();
    RefreshCollections::queueTask();
}

test('a new part notifies whoever follows the collection', function () {
    Http::fake(matrixSequence(matrixGrown()));

    $user = User::factory()->create();
    $list = openMatrix($user);

    followCollection($user, $list, FollowMode::Follow);

    runRefresh();

    $notification = $user->notifications()->sole();

    expect($notification->data['event'])->toBe('collection_new_item')
        ->and($notification->data['media']['title'])->toBe('The Matrix Resurrections')
        ->and($notification->data['collectionTitle'])->toBe('The Matrix Collection')
        ->and($notification->data['collectionProvider'])->toBe('tmdb')
        ->and($notification->data['collectionRef'])->toBe('2344')
        ->and($notification->data['key'])->toBe('new_title:movie:624860');
});

test('a part the provider files under the saga long after it came out is not news', function () {
    Http::fake([
        ...matrixSequence([
            ...matrixGrown(),
            ['id' => 55931, 'title' => 'The Animatrix', 'poster_path' => '/a.jpg', 'release_date' => '2003-06-03'],
            ['id' => 999001, 'title' => 'The Matrix: Untitled', 'poster_path' => null, 'release_date' => ''],
        ]),
        '*/movie/55931*' => Http::response(['id' => 55931, 'title' => 'The Animatrix', 'poster_path' => '/a.jpg', 'release_date' => '2003-06-03']),
        '*/movie/999001*' => Http::response(['id' => 999001, 'title' => 'The Matrix: Untitled', 'poster_path' => null, 'release_date' => '']),
    ]);

    $user = User::factory()->create();
    $list = openMatrix($user);
    followCollection($user, $list, FollowMode::Follow);

    runRefresh();

    // The same window as a followed director's: only what is still ahead.
    expect($user->notifications()->get()->pluck('data.media.title')->all())->toBe(['The Matrix Resurrections'])
        ->and($list->items()->count())->toBe(5);
});

test("a game series' next game is news once it has a date, an undated one not yet", function () {
    enableGamesModule();
    $user = User::factory()->create();
    $series = MediaList::factory()->collection('igdb', '62')->create(['title' => 'The Witcher']);
    Follow::factory()->create(['user_id' => $user->id, 'list_id' => $series->id]);

    $dated = MediaItem::factory()->game()->create(['title' => 'The Witcher 4', 'release_date' => today()->addYear()->toDateString()]);
    $undated = MediaItem::factory()->game()->create(['title' => 'The Witcher Remake', 'release_date' => null]);

    app(NotifyCollectionAdditions::class)->execute(new CollectionSyncResult($series, [$dated->id, $undated->id], [], wasCreated: false));

    expect($user->notifications()->get()->pluck('data.media.title')->all())->toBe(['The Witcher 4']);
});

test("a book series' new volume notifies its followers, a new companion nobody", function () {
    enableBooksModule();
    $grown = jsonFixture('hardcover/series');
    // The Two Towers stands in for a volume still to come.
    $grown['data']['series_by_pk']['book_series'][4]['book']['release_date'] = today()->addMonths(3)->toDateString();
    $grown['data']['series_by_pk']['book_series'][] = ['position' => 3.5, 'book' => ['id' => 9001, 'title' => 'A companion novella']];
    $first = $grown;
    // Before: The Two Towers not out yet, the novella not written.
    $first['data']['series_by_pk']['book_series'] = array_slice($grown['data']['series_by_pk']['book_series'], 0, 4);

    fakeHardcoverSeries($first, $grown);

    $user = User::factory()->create();
    $this->actingAs($user)->get(route('collections.show', ['provider' => 'hardcover', 'ref' => '127396']));
    $list = MediaList::query()->collections()->sole();

    followCollection($user, $list, FollowMode::Follow);

    runRefresh();

    $notification = $user->notifications()->sole();

    expect($notification->data['event'])->toBe('collection_new_item')
        ->and($notification->data['media']['title'])->toBe('The Two Towers')
        ->and($notification->data['collectionTitle'])->toBe('Middle Earth')
        ->and($notification->data['collectionProvider'])->toBe('hardcover');
});

test('a collection without a bell notifies nobody', function () {
    Http::fake(matrixSequence(matrixGrown()));

    $user = User::factory()->create();
    openMatrix($user);

    runRefresh();

    expect($user->notifications()->count())->toBe(0);
});

test('a muted collection stays silent', function () {
    Http::fake(matrixSequence(matrixGrown()));

    $user = User::factory()->create();
    $list = openMatrix($user);

    followCollection($user, $list, FollowMode::Mute);

    runRefresh();

    expect($user->notifications()->count())->toBe(0);
});

test('the toggle switches the event off, not the bell on the new part', function () {
    Http::fake(matrixSequence(matrixGrown()));

    $user = User::factory()->create(['preferences' => ['notify_collection_items' => false]]);
    $list = openMatrix($user);

    followCollection($user, $list, FollowMode::Follow);

    runRefresh();

    $part = MediaItem::query()->where('type', 'movie')->where('provider_ref', '624860')->sole();

    expect($user->notifications()->count())->toBe(0)
        ->and($user->follows()->where('media_item_id', $part->id)->value('mode'))->toBe(FollowMode::Follow);
});

test('materializing a collection never announces its existing parts', function () {
    Http::fake(matrixSequence(matrixGrown()));

    $user = User::factory()->create();

    // The bell can only be clicked once the page exists, but the very first
    // sync must be silent no matter who is watching.
    $list = openMatrix($user);

    expect($user->notifications()->count())->toBe(0)
        ->and($list->items()->count())->toBe(2);
});

test('running the task twice does not repeat the event', function () {
    Http::fake(matrixSequence(matrixGrown()));

    $user = User::factory()->create();
    $list = openMatrix($user);

    followCollection($user, $list, FollowMode::Follow);

    runRefresh();
    runRefresh();

    expect($user->notifications()->count())->toBe(1);
});

test('a part dropped by the provider notifies nobody', function () {
    // The second fetch LOSES Reloaded instead of gaining anything.
    Http::fake(matrixSequence([
        ['id' => 603, 'title' => 'The Matrix', 'poster_path' => '/m1.jpg', 'release_date' => '1999-03-30'],
    ]));

    $user = User::factory()->create();
    $list = openMatrix($user);

    followCollection($user, $list, FollowMode::Follow);

    runRefresh();

    expect($user->notifications()->count())->toBe(0)
        ->and($list->items()->count())->toBe(1);
});

test('only the followers of that collection hear about it', function () {
    Http::fake(matrixSequence(matrixGrown()));

    $follower = User::factory()->create();
    $bystander = User::factory()->create();
    $list = openMatrix($follower);

    followCollection($follower, $list, FollowMode::Follow);

    runRefresh();

    expect($follower->notifications()->count())->toBe(1)
        ->and($bystander->notifications()->count())->toBe(0);
});

test('the new part inherits the saga bell', function () {
    Http::fake(matrixSequence(matrixGrown()));

    $user = User::factory()->create();
    $list = openMatrix($user);

    followCollection($user, $list, FollowMode::Follow);

    runRefresh();

    $item = MediaItem::query()->where('provider_ref', '624860')->sole();
    $row = $user->follows()->where('media_item_id', $item->id)->sole();

    expect($row->mode)->toBe(FollowMode::Follow)
        ->and($row->viaLists()->pluck('lists.id')->all())->toBe([$list->id])
        // Nothing is written to the backlog — the bell alone carries it.
        ->and($user->watchlist()->items()->where('media_item_id', $item->id)->exists())->toBeFalse();
});

test('a manual refresh delivers the event instead of swallowing it', function () {
    Http::fake(matrixSequence(matrixGrown()));

    $user = User::factory()->create();
    $list = openMatrix($user);

    followCollection($user, $list, FollowMode::Follow);

    Cache::flush();

    $this->actingAs($user)
        ->post(route('collections.refresh', ['provider' => 'tmdb', 'ref' => '2344']))
        ->assertRedirect();

    expect($user->notifications()->count())->toBe(1);
});

test('more than five new parts arrive as one notification listing them all', function () {
    $more = array_map(fn (int $id): array => ['id' => $id, 'title' => "Part {$id}", 'poster_path' => "/p{$id}.jpg", 'release_date' => '2027-01-01'], range(701, 706));

    Http::fake([
        ...matrixSequence([...matrixGrown(), ...$more]),
        ...array_combine(
            array_map(fn (int $id): string => "*/movie/{$id}*", range(701, 706)),
            array_map(fn (array $part) => Http::response($part), $more),
        ),
    ]);

    $user = User::factory()->create();
    $list = openMatrix($user);
    followCollection($user, $list, FollowMode::Follow);

    runRefresh();

    $notification = $user->notifications()->sole();

    expect($notification->data['event'])->toBe('collection_new_item')
        ->and($notification->data['items'])->toHaveCount(7)
        ->and(array_column($notification->data['items'], 'title'))->toContain('The Matrix Resurrections', 'Part 701');

    // Its page lists every part, and the parts carry the saga's bell.
    $this->actingAs($user)
        ->get(route('notifications.show', $notification->id))
        ->assertInertia(fn ($page) => $page->component('NotificationDetails')->has('items', 7));

    expect($user->follows()->whereNotNull('media_item_id')->count())->toBe(7);

    // A second run with the same parts stays silent.
    runRefresh();

    expect($user->notifications()->count())->toBe(1);
});
