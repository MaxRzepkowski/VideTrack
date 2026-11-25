<?php

use App\Actions\Library\MarkWatched;
use App\Library\WatchedAt;
use App\Models\Follow;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\Person;
use App\Models\User;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Sources\ReleasedSinceAdded;

/**
 * @return list<string> the titles put forward, in order
 */
function releasedSinceAdded(User $user): array
{
    $groups = app(ReleasedSinceAdded::class)->groups($user, 'en');

    return $groups === [] ? [] : array_map(fn (Suggestion $suggestion): string => $suggestion->media->title, $groups[0]->suggestions);
}

function belled(User $user, MediaItem $item, string $on): void
{
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $item->id, 'created_at' => $on]);
}

test('a title belled before its release and out since is put forward', function () {
    $user = User::factory()->create();
    belled($user, MediaItem::factory()->movie()->create(['title' => 'Out now', 'release_date' => '2026-09-01']), '2026-03-14 10:00:00');
    // Belled after it was already out, and one still to come.
    belled($user, MediaItem::factory()->movie()->create(['title' => 'Old', 'release_date' => '2020-01-01']), '2026-03-14 10:00:00');
    belled($user, MediaItem::factory()->movie()->create(['title' => 'Later', 'release_date' => today()->addMonth()->toDateString()]), '2026-03-14 10:00:00');

    expect(releasedSinceAdded($user))->toBe(['Out now']);
});

test('a bell rung by a followed creator counts, a muted title does not', function () {
    $user = User::factory()->create();
    $theirs = MediaItem::factory()->movie()->create(['title' => 'By a followed director', 'release_date' => '2026-09-01']);
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $theirs->id, 'created_at' => '2026-03-14 10:00:00'])
        ->viaPeople()->attach(Person::factory()->create());
    $muted = MediaItem::factory()->movie()->create(['title' => 'Muted', 'release_date' => '2026-09-01']);
    Follow::factory()->muted()->create(['user_id' => $user->id, 'media_item_id' => $muted->id, 'created_at' => '2026-03-14 10:00:00']);

    expect(releasedSinceAdded($user))->toBe(['By a followed director']);
});

test('a place on one of the user\'s lists counts like a bell', function () {
    $user = User::factory()->create();
    $list = MediaList::factory()->create(['user_id' => $user->id]);
    $film = MediaItem::factory()->movie()->create(['title' => 'Listed', 'release_date' => '2026-09-01']);
    ListItem::factory()->create(['list_id' => $list->id, 'media_item_id' => $film->id, 'created_at' => '2026-01-01 00:00:00']);
    // The same film on a stranger's list says nothing about this user.
    $other = MediaItem::factory()->movie()->create(['title' => 'Theirs', 'release_date' => '2026-09-01']);
    ListItem::factory()->create(['media_item_id' => $other->id, 'created_at' => '2026-01-01 00:00:00']);

    expect(releasedSinceAdded($user))->toBe(['Listed']);
});

test('what the user has watched since is not put forward', function () {
    $user = User::factory()->create();
    $film = MediaItem::factory()->movie()->create(['release_date' => '2026-09-01']);
    belled($user, $film, '2026-03-14 10:00:00');
    app(MarkWatched::class)->execute($user, $film, WatchedAt::now());

    expect(releasedSinceAdded($user))->toBe([]);
});

test('the latest premiere comes first and the card says when it was added and released', function () {
    $user = User::factory()->create();
    belled($user, MediaItem::factory()->movie()->create(['title' => 'Spring', 'release_date' => '2026-04-01']), '2026-01-05 10:00:00');
    belled($user, MediaItem::factory()->movie()->create(['title' => 'Autumn', 'release_date' => '2026-10-02']), '2026-03-14 10:00:00');

    $groups = app(ReleasedSinceAdded::class)->groups($user, 'en');

    expect(releasedSinceAdded($user))->toBe(['Autumn', 'Spring'])
        ->and($groups[0]->title)->toBeNull()
        ->and($groups[0]->suggestions[0]->reason)->toBe(['Released Oct 2, 2026', 'Added Mar 14, 2026']);
});

test('it needs no provider sets', function () {
    expect(app(ReleasedSinceAdded::class)->wantedSets(User::factory()->create()))->toBe([]);
});

test("a premiere counts as out from the viewer's own midnight, not the server's", function () {
    // 23:30 UTC is already the next day in Warsaw.
    $this->travelTo('2026-10-10 23:30:00');
    $user = User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw']]);
    belled($user, MediaItem::factory()->movie()->create(['title' => 'Out in Warsaw', 'release_date' => '2026-10-11']), '2026-10-05 10:00:00');

    expect(releasedSinceAdded($user))->toBe(['Out in Warsaw']);
});

test("the day a title was added is the viewer's day, so a bell rung on its premiere night is not an early one", function () {
    $this->travelTo('2026-10-12 12:00:00');
    $user = User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw']]);
    // 22:30 UTC on the 10th is half past midnight on the 11th in Warsaw: the premiere day itself.
    belled($user, MediaItem::factory()->movie()->create(['title' => 'Belled on the day', 'release_date' => '2026-10-11']), '2026-10-10 22:30:00');

    expect(releasedSinceAdded($user))->toBe([]);
});
