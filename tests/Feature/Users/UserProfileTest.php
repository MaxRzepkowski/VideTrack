<?php

use App\Actions\Library\MarkWatched;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login', function () {
    $user = User::factory()->create();

    $this->get(route('users.show', ['user' => $user->username]))->assertRedirect(route('login'));
});

test('a profile is visible to other users of the instance', function () {
    $owner = User::factory()->create(['bio' => 'Hello there']);
    $viewer = User::factory()->create();

    $movie = MediaItem::factory()->movie()->create(['metadata' => ['genres' => ['Drama', 'Sci-Fi']]]);
    app(MarkWatched::class)->execute($owner, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));
    $owner->favorites()->create(['media_item_id' => $movie->id]);
    $owner->ratings()->create(['media_item_id' => $movie->id, 'rating' => 9]);

    $this->actingAs($viewer)
        ->get(route('users.show', ['user' => $owner->username]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/UserProfile')
            ->where('profile.username', $owner->username)
            ->where('profile.bio', 'Hello there')
            ->where('isOwner', false)
            ->where('favoritesCount', 1)
            ->where('favorites.0.title', $movie->title));
});

test('a private profile is hidden from other users', function () {
    $owner = User::factory()->create(['is_private' => true]);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('users.show', ['user' => $owner->username]))
        ->assertNotFound();
});

test('a private profile stays visible to its owner and admins', function () {
    $owner = User::factory()->create(['is_private' => true]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($owner)
        ->get(route('users.show', ['user' => $owner->username]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('isOwner', true));

    $this->actingAs($admin)
        ->get(route('users.show', ['user' => $owner->username]))
        ->assertOk();
});

test('the profile carries no lists at all — the tab behind them does', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();

    MediaList::factory()->create(['user_id' => $owner->id, 'title' => 'Public picks', 'is_public' => true]);

    $this->actingAs($viewer)
        ->get(route('users.show', ['user' => $owner->username]))
        ->assertInertia(fn (Assert $page) => $page->missing('lists'));

    $this->actingAs($owner)
        ->get(route('users.show', ['user' => $owner->username]))
        ->assertInertia(fn (Assert $page) => $page->missing('lists'));
});

test('the profile counts the stats of the user it is about', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();

    $movie = MediaItem::factory()->movie()->create(['runtime' => 120]);
    app(MarkWatched::class)->execute($owner, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));

    // A visitor reads the owner's numbers, not their own empty ones.
    $this->actingAs($viewer)
        ->get(route('users.show', ['user' => $owner->username]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.movie.watched', 1)
            ->where('stats.movie.amount', 120)
            ->where('stats.show.watched', 0));
});

test('the user lists page reuses the index with owner context', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();

    MediaList::factory()->create(['user_id' => $owner->id, 'title' => 'Public picks', 'is_public' => true]);
    MediaList::factory()->create(['user_id' => $owner->id, 'title' => 'Secret stash', 'is_public' => false]);

    $this->actingAs($viewer)
        ->get(route('users.lists', ['user' => $owner->username]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('lists/ListIndex')
            ->where('isOwner', false)
            ->where('profile.username', $owner->username)
            // The public list and the backlog; never the private one.
            ->has('lists', 2)
            ->where('lists', fn ($lists) => collect($lists)->pluck('title')->doesntContain('Secret stash')));
});

test('unknown usernames return 404', function () {
    $this->actingAs(User::factory()->create())
        ->get('/user/does_not_exist')
        ->assertNotFound();
});
