<?php

use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\MediaUserState;
use App\Models\Person;
use App\Models\Play;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function makeList(User $user, array $attributes = [], int $items = 0): MediaList
{
    /** @var MediaList $list */
    $list = $user->lists()->create(['title' => 'Sci-Fi', ...$attributes]);

    for ($position = 1; $position <= $items; $position++) {
        $list->items()->create([
            'media_item_id' => MediaItem::factory()->movie()->create()->id,
            'position' => $position,
        ]);
    }

    return $list;
}

test('guests are redirected to login', function () {
    $list = makeList(User::factory()->create(), ['is_public' => true]);

    $this->get('/lists')->assertRedirect(route('login'));
    $this->get(listPage($list))->assertRedirect(route('login'));
});

test('the index shows own lists with the watchlist first', function () {
    $user = User::factory()->create();
    makeList($user, [], 2);

    $this->actingAs($user)
        ->get('/lists')
        ->assertInertia(fn (Assert $page) => $page
            ->component('lists/ListIndex')
            ->count('lists', 2)
            ->where('lists.0.systemKey', 'watchlist')
            ->where('lists.1.title', 'Sci-Fi')
            ->where('lists.1.itemCount', 2));
});

test('a list page renders items in manual order with user states', function () {
    $user = User::factory()->create();
    $list = makeList($user, [], 2);
    $list->items()->orderBy('position')->first()->update(['position' => 3]); // manual order: item 2, item 1

    $first = $list->items()->orderBy('position')->first();

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page
            ->component('lists/ListDetails')
            ->where('list.isOwner', true)
            ->where('list.canDelete', true)
            ->count('items', 2)
            ->where('items.0.id', $first->id)
            ->has('userStates'));
});

test('what is not out yet stays off the progress until it is, and is counted apart', function () {
    $user = User::factory()->create();
    $list = makeList($user);
    $watched = MediaItem::factory()->movie()->create(['release_date' => '1999-03-31']);
    $outToday = MediaItem::factory()->movie()->create(['release_date' => today()->toDateString()]);
    $announced = MediaItem::factory()->movie()->create(['release_date' => today()->addDay()->toDateString()]);
    $undated = MediaItem::factory()->movie()->create(['release_date' => null]);

    foreach ([$watched, $outToday, $announced, $undated] as $position => $film) {
        $list->items()->create(['media_item_id' => $film->id, 'position' => $position + 1]);
    }

    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $watched->id, 'watched_at' => now()]);
    projectEveryLibrary();

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.trackableCount', 4)
            ->where('stats.completedCount', 1)
            ->where('stats.upcomingCount', 2));
});

test('list statistics total every position but only media contributes to size and progress', function () {
    $user = User::factory()->create();
    $list = makeList($user);
    $watchedMovie = MediaItem::factory()->movie()->create(['runtime' => 120, 'total_runtime' => 120]);
    $unwatchedShow = MediaItem::factory()->show()->create(['total_runtime' => 300]);
    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);

    $list->items()->create(['media_item_id' => $watchedMovie->id, 'position' => 1]);
    $list->items()->create(['media_item_id' => $unwatchedShow->id, 'position' => 2]);
    $list->items()->create(['person_id' => $person->id, 'position' => 3]);
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $watchedMovie->id, 'watched_at' => now()]);
    projectEveryLibrary();

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.itemCount', 3)
            ->where('stats.trackableCount', 2)
            ->where('stats.completedCount', 1)
            ->where('stats.inProgressCount', 0)
            ->where('stats.types', [
                ['type' => 'movie', 'count' => 1],
                ['type' => 'person', 'count' => 1],
                ['type' => 'show', 'count' => 1],
            ])
            ->count('stats.totals', 1)
            ->where('stats.totals.0.unit', 'minutes')
            ->where('stats.totals.0.estimated', false)
            ->where('stats.totals.0.amount', 420)
            ->where('stats.totals.0.behindAmount', 120)
            ->where('stats.totals.0.remainingAmount', 300)
            ->where('stats.totals.0.itemsCounted', 2)
            ->where('stats.totals.0.itemsTotal', 2));
});

test('a list of people has no size or completion denominator', function () {
    $user = User::factory()->create();
    $list = makeList($user);
    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);

    $list->items()->create(['person_id' => $person->id, 'position' => 1]);

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.itemCount', 1)
            ->where('stats.trackableCount', 0)
            ->where('stats.completedCount', 0)
            ->where('stats.inProgressCount', 0)
            ->count('stats.totals', 0));
});

test('reading positions total in their own units, counting the page they are left on', function () {
    enableBooksModule();
    enableMangaModule();

    $user = User::factory()->create();
    $list = makeList($user);
    $book = MediaItem::factory()->book()->create();   // 320 pages
    $manga = MediaItem::factory()->manga()->create(); // 162 chapters

    $list->items()->create(['media_item_id' => $book->id, 'position' => 1]);
    $list->items()->create(['media_item_id' => $manga->id, 'position' => 2]);
    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $book->id,
        'status' => MediaUserStatus::InProgress,
        'progress' => 80,
    ]);
    projectEveryLibrary();

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.trackableCount', 2)
            ->where('stats.completedCount', 0)
            ->where('stats.inProgressCount', 1)
            ->count('stats.totals', 2)
            ->where('stats.totals.0.unit', 'pages')
            ->where('stats.totals.0.amount', 320)
            ->where('stats.totals.0.behindAmount', 80)
            ->where('stats.totals.0.remainingAmount', 240)
            ->where('stats.totals.1.unit', 'chapters')
            ->where('stats.totals.1.amount', 162)
            ->where('stats.totals.1.behindAmount', 0)
            ->where('stats.totals.1.remainingAmount', 162));
});

test('a read started or paused before its first page counts as in progress', function () {
    enableBooksModule();
    enableGamesModule();

    $user = User::factory()->create();
    $list = makeList($user);
    $book = MediaItem::factory()->book()->create();
    $game = MediaItem::factory()->game()->create();

    $list->items()->create(['media_item_id' => $book->id, 'position' => 1]);
    $list->items()->create(['media_item_id' => $game->id, 'position' => 2]);
    MediaUserState::factory()->for($user)->for($book, 'mediaItem')->onHold()->create();
    MediaUserState::factory()->for($user)->for($game, 'mediaItem')->create();
    projectEveryLibrary();

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.trackableCount', 2)
            ->where('stats.inProgressCount', 2));
});

test('a game is sized by its estimated completion time, never mixed into real runtimes', function () {
    enableGamesModule();

    $user = User::factory()->create();
    $list = makeList($user);
    $movie = MediaItem::factory()->movie()->create(['runtime' => 120, 'total_runtime' => 120]);
    $game = MediaItem::factory()->game()->create(['runtime' => 1800, 'total_runtime' => 1800]);

    $list->items()->create(['media_item_id' => $movie->id, 'position' => 1]);
    $list->items()->create(['media_item_id' => $game->id, 'position' => 2]);
    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $game->id,
        'status' => MediaUserStatus::InProgress,
        'progress' => 600,
    ]);
    projectEveryLibrary();

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page
            ->count('stats.totals', 2)
            ->where('stats.totals.0.unit', 'minutes')
            ->where('stats.totals.0.estimated', false)
            ->where('stats.totals.0.amount', 120)
            ->where('stats.totals.1.unit', 'minutes')
            ->where('stats.totals.1.estimated', true)
            ->where('stats.totals.1.amount', 1800)
            ->where('stats.totals.1.behindAmount', 600)
            ->where('stats.totals.1.remainingAmount', 1200));
});

test('a dropped read keeps its pages behind it without counting as open or finished', function () {
    enableBooksModule();

    $user = User::factory()->create();
    $list = makeList($user);
    $book = MediaItem::factory()->book()->create(); // 320 pages

    $list->items()->create(['media_item_id' => $book->id, 'position' => 1]);
    Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $book->id,
        'watched_at' => now(),
        'outcome' => PlayOutcome::Dropped,
        'amount' => 300,
    ]);
    projectEveryLibrary();

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.trackableCount', 1)
            ->where('stats.completedCount', 0)
            ->where('stats.inProgressCount', 0)
            ->where('stats.droppedCount', 1)
            ->where('stats.totals.0.behindAmount', 300)
            ->where('stats.totals.0.remainingAmount', 20));
});

test('a show abandoned part way keeps the hours watched behind it', function () {
    $user = User::factory()->create();
    $list = makeList($user);
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $show->update(['total_runtime' => 100]);
    $season = $show->children()->firstOrFail();
    $season->children()->update(['runtime' => 50]);
    $firstEpisode = $season->children()->orderBy('number')->firstOrFail();

    $list->items()->create(['media_item_id' => $show->id, 'position' => 1]);
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $firstEpisode->id, 'watched_at' => now()]);
    Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $show->id,
        'watched_at' => now(),
        'outcome' => PlayOutcome::Dropped,
    ]);
    projectEveryLibrary();

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.completedCount', 0)
            ->where('stats.inProgressCount', 0)
            ->where('stats.droppedCount', 1)
            ->where('stats.totals.0.amount', 100)
            ->where('stats.totals.0.behindAmount', 50)
            ->where('stats.totals.0.remainingAmount', 50));
});

test('a size the provider never gave is owned up to rather than counted as zero', function () {
    enableMangaModule();

    $user = User::factory()->create();
    $list = makeList($user);
    $known = MediaItem::factory()->manga()->create();
    $ongoing = MediaItem::factory()->manga()->create(['metadata' => null]);

    $list->items()->create(['media_item_id' => $known->id, 'position' => 1]);
    $list->items()->create(['media_item_id' => $ongoing->id, 'position' => 2]);

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page
            ->count('stats.totals', 1)
            ->where('stats.totals.0.amount', 162)
            ->where('stats.totals.0.itemsCounted', 1)
            ->where('stats.totals.0.itemsTotal', 2));
});

test('season and episode positions carry show context', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01']]);
    $season = $show->children()->firstOrFail();
    $episode = $season->children()->firstOrFail();

    $list = makeList($user);
    $list->items()->create(['media_item_id' => $season->id, 'position' => 1]);
    $list->items()->create(['media_item_id' => $episode->id, 'position' => 2]);

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.media.extra.showTitle', $show->title)
            ->where('items.1.media.extra.showTitle', $show->title)
            ->where('items.1.media.extra.seasonNumber', 1));
});

test('a private list is hidden from other users, a public one is viewable', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $private = makeList($owner, ['is_public' => false]);
    $public = makeList($owner, ['is_public' => true]);

    $this->actingAs($viewer)->get(listPage($private))->assertNotFound();

    $this->actingAs($viewer)
        ->get(listPage($public))
        ->assertInertia(fn (Assert $page) => $page
            ->where('list.isOwner', false)
            ->where('list.canDelete', false));
});

test('a list lives under its owner, and nowhere else', function () {
    $owner = User::factory()->create(['username' => 'ana']);
    $other = User::factory()->create(['username' => 'bo']);
    $list = makeList($owner, ['is_public' => true]);

    $this->actingAs($other)->get("/user/ana/lists/{$list->id}")->assertOk();
    $this->actingAs($other)->get("/user/bo/lists/{$list->id}")->assertNotFound();
    $this->actingAs($owner)->get("/list/{$list->id}")->assertNotFound();
});

test('a private profile keeps its public lists with it', function () {
    $owner = User::factory()->create(['is_private' => true]);
    $list = makeList($owner, ['is_public' => true]);

    $this->actingAs(User::factory()->create())->get(listPage($list))->assertNotFound();
    $this->actingAs($owner)->get(listPage($list))->assertOk();
});

test('the own lists index names whose lists they are', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('lists.index'))
        ->assertInertia(fn (Assert $page) => $page->where('owner', $user->username));
});

test('creating a list answers the new list', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('ui.lists.store'), ['title' => 'Halloween marathon'])->assertCreated();

    $list = MediaList::where('title', 'Halloween marathon')->firstOrFail();

    $response->assertJsonPath('data.id', $list->id);
});

test('the owner can edit title, description and visibility', function () {
    $user = User::factory()->create();
    $list = makeList($user);

    $this->actingAs($user)
        ->patchJson(route('ui.lists.update', $list), ['title' => 'Renamed', 'description' => 'Better desc', 'is_public' => true])
        ->assertOk();

    expect($list->refresh())
        ->title->toBe('Renamed')
        ->description->toBe('Better desc')
        ->is_public->toBeTrue();
});

test('other users cannot edit, reorder or delete a foreign list', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $list = makeList($owner, ['is_public' => true], 1);

    $this->actingAs($intruder)
        ->patchJson(route('ui.lists.update', $list), ['title' => 'Hacked', 'is_public' => false])
        ->assertNotFound();

    $this->actingAs($intruder)
        ->putJson(route('ui.lists.items.reorder', $list), ['item_ids' => []])
        ->assertNotFound();

    $this->actingAs($intruder)->deleteJson(route('ui.lists.destroy', $list))->assertNotFound();
});

test('the watchlist cannot be renamed or deleted', function () {
    $user = User::factory()->create();
    $watchlist = $user->watchlist();

    $this->actingAs($user)
        ->patchJson(route('ui.lists.update', $watchlist), ['title' => 'Renamed', 'description' => 'x', 'is_public' => true])
        ->assertOk();

    expect($watchlist->refresh())
        ->title->toBeNull()
        ->description->toBeNull()
        ->is_public->toBeTrue();

    $this->actingAs($user)->deleteJson(route('ui.lists.destroy', $watchlist))->assertForbidden();
    expect(MediaList::count())->toBe(1);
});

test('the watchlist changes visibility with no title sent, as it has none', function () {
    $user = User::factory()->create();
    $watchlist = $user->watchlist();

    $this->actingAs($user)
        ->patchJson(route('ui.lists.update', $watchlist), ['title' => null, 'description' => null, 'is_public' => true])
        ->assertOk();

    expect($watchlist->refresh()->is_public)->toBeTrue();
});

test('a list of the user\'s own still needs a title', function () {
    $user = User::factory()->create();
    $list = makeList($user, [], 0);

    $this->actingAs($user)
        ->patchJson(route('ui.lists.update', $list), ['title' => null, 'is_public' => true])
        ->assertJsonValidationErrors('title');
});

test('deleting a custom list removes it with its items', function () {
    $user = User::factory()->create();
    $list = makeList($user, [], 2);

    $this->actingAs($user)
        ->deleteJson(route('ui.lists.destroy', $list))
        ->assertNoContent();

    expect(MediaList::query()->whereKey($list->id)->exists())->toBeFalse()
        ->and(ListItem::count())->toBe(0);
});

test('duplicating copies items and order into an own list with the default visibility', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $list = makeList($owner, ['is_public' => true], 3);

    $response = $this->actingAs($viewer)->postJson(route('ui.lists.duplicate', $list))->assertCreated();

    $copy = MediaList::where('user_id', $viewer->id)->whereNull('system_key')->firstOrFail();

    $response->assertJsonPath('data.id', $copy->id);

    expect($copy)
        ->title->toBe('Sci-Fi (copy)')
        ->is_public->toBeTrue()
        ->and($copy->items()->pluck('media_item_id')->all())
        ->toBe($list->items()->pluck('media_item_id')->all());
});

test('a foreign private list cannot be duplicated', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $list = makeList($owner, ['is_public' => false]);

    $this->actingAs($viewer)->postJson(route('ui.lists.duplicate', $list))->assertNotFound();
});

test('reordering applies the drag & drop payload as positions 1..n', function () {
    $user = User::factory()->create();
    $list = makeList($user, [], 3);
    [$a, $b, $c] = $list->items()->orderBy('position')->get();

    $this->actingAs($user)
        ->putJson(route('ui.lists.items.reorder', $list), ['item_ids' => [$c->id, $a->id, $b->id]])
        ->assertNoContent();

    expect($list->items()->orderBy('position')->pluck('id')->all())
        ->toBe([$c->id, $a->id, $b->id])
        ->and($list->items()->orderBy('position')->pluck('position')->all())
        ->toBe([1, 2, 3]);
});

test('reordering ignores item ids from other lists', function () {
    $user = User::factory()->create();
    $list = makeList($user, [], 2);
    $other = makeList($user, ['title' => 'Other'], 1);
    [$a, $b] = $list->items()->orderBy('position')->get();
    $foreign = $other->items()->firstOrFail();

    $this->actingAs($user)
        ->putJson(route('ui.lists.items.reorder', $list), ['item_ids' => [$b->id, $foreign->id, $a->id]])
        ->assertNoContent();

    expect($list->items()->orderBy('position')->pluck('id')->all())->toBe([$b->id, $a->id])
        ->and($foreign->refresh()->list_id)->toBe($other->id);
});

test('the index reorders lists and ignores foreign ids', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $a = makeList($user, ['title' => 'A']);
    $b = makeList($user, ['title' => 'B']);
    $foreign = makeList($stranger, ['title' => 'Foreign']);

    $this->actingAs($user)
        ->putJson(route('ui.lists.reorder'), ['list_ids' => [$b->id, $foreign->id, $a->id]])
        ->assertNoContent();

    expect($user->lists()->whereNull('system_key')->orderBy('position')->pluck('id')->all())->toBe([$b->id, $a->id])
        ->and($user->lists()->whereNull('system_key')->orderBy('position')->pluck('position')->all())->toBe([1, 2])
        ->and($foreign->refresh()->user_id)->toBe($stranger->id);

    // The index renders the persisted order; the backlog created by the visit
    // itself keeps leading it.
    $this->actingAs($user)
        ->get('/lists')
        ->assertInertia(fn (Assert $page) => $page
            ->where('lists.0.systemKey', 'watchlist')
            ->where('lists.1.id', $b->id)
            ->where('lists.2.id', $a->id)
            ->has('lists.1.createdAt'));
});

test('the backlog cannot be dragged out of the lead', function () {
    $user = User::factory()->create();
    $watchlist = $user->watchlist();
    $a = makeList($user, ['title' => 'A']);
    $b = makeList($user, ['title' => 'B']);

    // A payload that tries to bury the backlog between the custom lists: it is
    // ignored, and only A and B are renumbered.
    $this->actingAs($user)
        ->putJson(route('ui.lists.reorder'), ['list_ids' => [$b->id, $watchlist->id, $a->id]])
        ->assertNoContent();

    expect($watchlist->refresh()->position)->toBe(0)
        ->and($user->lists()->whereNull('system_key')->orderBy('position')->pluck('id')->all())->toBe([$b->id, $a->id])
        ->and($user->lists()->whereNull('system_key')->orderBy('position')->pluck('position')->all())->toBe([1, 2]);

    $this->actingAs($user)
        ->get('/lists')
        ->assertInertia(fn (Assert $page) => $page
            ->where('lists.0.systemKey', 'watchlist')
            ->where('lists.1.id', $b->id)
            ->where('lists.2.id', $a->id));
});

test('a new list lands at the end of the manual order, the backlog stays first', function () {
    $user = User::factory()->create();
    $first = makeList($user, ['title' => 'First']);
    $watchlist = $user->watchlist();
    $last = makeList($user, ['title' => 'Last']);

    $this->actingAs($user)
        ->get('/lists')
        ->assertInertia(fn (Assert $page) => $page
            ->where('lists.0.id', $watchlist->id)
            ->where('lists.1.id', $first->id)
            ->where('lists.2.id', $last->id));
});

test('removing a position deletes only that entry', function () {
    $user = User::factory()->create();
    $list = makeList($user, [], 2);
    $item = $list->items()->orderBy('position')->firstOrFail();

    $this->actingAs($user)
        ->deleteJson(route('ui.lists.items.destroy', ['list' => $list, 'item' => $item]))
        ->assertNoContent();

    expect($list->items()->count())->toBe(1)
        ->and(MediaItem::count())->toBe(2); // media rows stay
});

test('an item cannot be removed through a different list', function () {
    $user = User::factory()->create();
    $list = makeList($user, [], 1);
    $other = makeList($user, ['title' => 'Other'], 1);
    $foreign = $other->items()->firstOrFail();

    $this->actingAs($user)
        ->deleteJson(route('ui.lists.items.destroy', ['list' => $list, 'item' => $foreign]))
        ->assertNotFound();

    expect(ListItem::count())->toBe(2);
});
