<?php

use App\Actions\Library\MarkWatched;
use App\Enums\ActivityGrouping;
use App\Enums\ActivitySort;
use App\Library\WatchedAt;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

// The owner's panels as someone else reads them: the list and what sits under
// a card are the owner's; the visitor sorts by their own rating too and hides
// what they already know.

function rate(User $user, MediaItem $item, int $rating): void
{
    Rating::factory()->for($user)->for($item, 'mediaItem')->create(['rating' => $rating]);
}

function watchedBy(User $user, MediaItem $item, string $moment): void
{
    app(MarkWatched::class)->execute($user, $item, WatchedAt::at(CarbonImmutable::parse($moment)));
}

test('a visitor opens the ratings on the owner’s scores, shown under each card', function () {
    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $low = MediaItem::factory()->movie()->create(['title' => 'Low']);
    $high = MediaItem::factory()->movie()->create(['title' => 'High']);

    rate($owner, $low, 3);
    rate($owner, $high, 9);
    rate($visitor, $low, 10);

    $this->actingAs($visitor)
        ->get(route('users.ratings', ['user' => $owner->username]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', ActivitySort::OwnerRating->value)
            ->where('entries.0.media.title', 'High')
            ->where('entries.0.rating', 9)
            ->where('entries.1.rating', 3));

    // Their own rating puts the visitor's favourite first, the rest after.
    $this->actingAs($visitor)
        ->get(route('users.ratings', ['user' => $owner->username, 'sort' => ActivitySort::MyRating->value]))
        ->assertInertia(fn (Assert $page) => $page->where('entries.0.media.title', 'Low'));

    $this->actingAs($visitor)
        ->get(route('users.ratings', ['user' => $owner->username, 'sort' => ActivitySort::MyRating->value, 'dir' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page
            // Unrated by the visitor: last, whichever way the rating runs.
            ->where('entries.1.media.title', 'High'));

    // What the visitor rated hides, the owner's ratings do not.
    $this->actingAs($visitor)
        ->get(route('users.ratings', ['user' => $owner->username, 'hide_rated' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.title', 'High'));
});

test('the owner has one rating to sort by, their own', function () {
    $owner = User::factory()->create();

    rate($owner, MediaItem::factory()->movie()->create(), 7);

    $this->actingAs($owner)
        ->get(route('users.ratings', ['user' => $owner->username, 'sort' => ActivitySort::OwnerRating->value]))
        ->assertInertia(fn (Assert $page) => $page->where('filters.sort', ActivitySort::MyRating->value));
});

test('a visitor sorting the history by the owner’s rating reads it under each card', function () {
    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $liked = MediaItem::factory()->movie()->create(['title' => 'Liked']);
    $unrated = MediaItem::factory()->movie()->create(['title' => 'Unrated']);
    $disliked = MediaItem::factory()->movie()->create(['title' => 'Disliked']);

    foreach ([$liked, $unrated, $disliked] as $index => $movie) {
        watchedBy($owner, $movie, '2026-01-0'.($index + 1).' 20:00');
    }

    rate($owner, $liked, 9);
    rate($owner, $disliked, 2);
    rate($visitor, $disliked, 10);

    $history = fn (array $query) => $this->actingAs($visitor)
        ->get(route('users.history', ['user' => $owner->username, 'group' => 'grid', ...$query]));

    $history(['sort' => ActivitySort::OwnerRating->value, 'dir' => 'asc'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.media.title', 'Disliked')
            ->where('ownerRatingsShown', true)
            ->where('entries.0.rating', 2)
            ->where('entries.2.media.title', 'Unrated')
            ->where('entries.2.rating', null));

    // Grouped by day, or sorted by anything but a rating, the page runs by
    // something else, and the cards carry the date.
    $history(['sort' => ActivitySort::OwnerRating->value, 'group' => ActivityGrouping::Day->value])
        ->assertInertia(fn (Assert $page) => $page
            ->where('ownerRatingsShown', false)
            ->where('entries.0.rating', null));

    $history(['sort' => ActivitySort::Title->value])
        ->assertInertia(fn (Assert $page) => $page
            ->where('ownerRatingsShown', false)
            ->where('entries.0.rating', null));

    // Sorted by the visitor's own (on the poster), the owner's is what they compare it with.
    $history(['sort' => ActivitySort::MyRating->value])
        ->assertInertia(fn (Assert $page) => $page
            ->where('ownerRatingsShown', true)
            ->where('entries.0.media.title', 'Disliked')
            ->where('entries.0.rating', 2));
});

test('rolled up to shows, both ratings are the show’s', function () {
    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $loved = makeShowTree([1 => ['2020-01-01']]);
    $meh = makeShowTree([1 => ['2020-01-01']]);

    watchedBy($owner, $loved, '2026-01-01 20:00');
    watchedBy($owner, $meh, '2026-01-02 20:00');
    rate($owner, $loved, 10);
    rate($owner, $meh, 4);
    // An episode's rating is not its show's.
    rate($owner, episodeOf($meh, 1, 1), 10);

    $this->actingAs($visitor)
        ->get(route('users.history', ['user' => $owner->username, 'type' => 'show', 'group' => 'grid', 'sort' => ActivitySort::OwnerRating->value]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.media.ref', $loved->provider_ref)
            ->where('entries.0.rating', 10)
            ->where('entries.1.rating', 4));
});

test('hiding what is on lists hides what is on the visitor’s, not the owner’s', function () {
    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $mine = MediaItem::factory()->movie()->create(['title' => 'On my list']);
    $theirs = MediaItem::factory()->movie()->create(['title' => 'On their list']);

    watchedBy($owner, $mine, '2026-01-01 20:00');
    watchedBy($owner, $theirs, '2026-01-02 20:00');
    ListItem::factory()->for(MediaList::factory()->for($visitor), 'list')->for($mine, 'mediaItem')->create();
    ListItem::factory()->for(MediaList::factory()->for($owner), 'list')->for($theirs, 'mediaItem')->create();

    $this->actingAs($visitor)
        ->get(route('users.history', ['user' => $owner->username, 'hide_listed' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.title', 'On their list'));
});

test('the favorites sort by the owner’s rating for a visitor too', function () {
    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    $owner->favorites()->create(['media_item_id' => $movie->id]);
    rate($owner, $movie, 6);

    $this->actingAs($visitor)
        ->get(route('users.favorites', ['user' => $owner->username, 'sort' => ActivitySort::OwnerRating->value]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', ActivitySort::OwnerRating->value)
            ->where('entries.0.rating', 6));
});

test('the owner’s own history carries nothing under the cards for them', function () {
    $owner = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    Play::factory()->for($owner)->for($movie)->create(['watched_at' => '2026-01-01 20:00']);
    projectLibrary($owner);
    rate($owner, $movie, 8);

    $this->actingAs($owner)
        ->get(route('users.history', ['user' => $owner->username, 'group' => 'grid', 'sort' => ActivitySort::MyRating->value]))
        ->assertInertia(fn (Assert $page) => $page->where('entries.0.rating', null));
});

test('each reader is offered the axes that mean something to them', function () {
    $owner = User::factory()->create();
    $visitor = User::factory()->create();

    $this->actingAs($visitor)
        ->get(route('users.history', ['user' => $owner->username]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sortOptions', fn ($sorts) => collect($sorts)->contains(ActivitySort::OwnerRating->value)
                && collect($sorts)->contains(ActivitySort::Completions->value)));

    $this->actingAs($owner)
        ->get(route('users.favorites', ['user' => $owner->username]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sortOptions', fn ($sorts) => ! collect($sorts)->contains(ActivitySort::OwnerRating->value)
                && ! collect($sorts)->contains(ActivitySort::Activity->value)
                && ! collect($sorts)->contains(ActivitySort::Completions->value)));
});
