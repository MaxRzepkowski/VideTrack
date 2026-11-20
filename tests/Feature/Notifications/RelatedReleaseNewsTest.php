<?php

use App\Actions\Library\SyncListMembership;
use App\Actions\Media\RefreshTitle;
use App\Catalog\GameKind;
use App\Enums\FollowMode;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\User;
use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;
use App\Notifications\NotificationCopy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Breath of the Wild on the user's Backlog, stored as the provider last
 * listed its releases of their own; the provider now lists the fixture's
 * (a remaster still to come, a DLC and an expansion long out).
 *
 * @param  array<string, mixed>  $metadata
 */
function refreshedGameFor(User $user, array $metadata): void
{
    enableGamesModule();
    Http::fake(['*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game')))]);

    $game = MediaItem::factory()->game()->create(['provider_ref' => '7346', 'title' => 'Breath of the Wild', 'metadata' => $metadata]);
    app(SyncListMembership::class)->execute($user, $game, [$user->watchlist()->id]);

    Cache::flush();
    app(RefreshTitle::class)->execute($game, 'en');
}

/** @return list<MediaEventPayload> */
function newsOf(User $user): array
{
    return $user->notifications()->get()->map(fn ($notification): MediaEventPayload => MediaEventPayload::fromStored($notification->data))->values()->all();
}

test('a release still to come, listed for the first time, is announced to whoever asked for announcements', function () {
    $user = User::factory()->create(['preferences' => ['notify_related_announced' => true]]);

    refreshedGameFor($user, ['relatedReleases' => []]);

    $news = newsOf($user);
    $copy = app(NotificationCopy::class);

    expect($news)->toHaveCount(1)
        ->and($news[0]->type)->toBe(MediaEventType::RelatedAnnounced)
        ->and($news[0]->key)->toBe('related_announced:game:5003')
        ->and($copy->title($news[0], 'en'))->toBe('Announced: Breath of the Wild HD')
        // What it is and whose, then when.
        ->and($copy->detail($news[0], 'en'))->toHaveCount(3)
        ->and(array_slice($copy->detail($news[0], 'en'), 0, 2))->toBe(['Remaster', 'The Legend of Zelda: Breath of the Wild'])
        ->and($news[0]->date)->toBe('2027-03-12');
});

test('nobody hears of an announcement unless they asked', function () {
    $user = User::factory()->create();

    refreshedGameFor($user, ['relatedReleases' => []]);

    expect(newsOf($user))->toBe([]);
});

test('a game whose releases of their own were never listed announces none of them on its first listing', function () {
    $user = User::factory()->create(['preferences' => ['notify_related_announced' => true]]);

    refreshedGameFor($user, []);

    expect(newsOf($user))->toBe([]);
});

test('a moved date of a release still to come is news like the game\'s own, keyed by the release', function () {
    $user = User::factory()->create();

    refreshedGameFor($user, ['relatedReleases' => [storedRelatedRelease('5003', 'Breath of the Wild HD', GameKind::Remaster, '2027-01-15')]]);

    $news = newsOf($user);

    expect($news)->toHaveCount(1)
        ->and($news[0]->type)->toBe(MediaEventType::DateChanged)
        ->and($news[0]->key)->toBe('date_changed:game:5003:2027-03-12')
        ->and($news[0]->oldDate)->toBe('2027-01-15')
        ->and(app(NotificationCopy::class)->detail($news[0], 'en'))->toHaveCount(3)
        ->and(app(NotificationCopy::class)->detail($news[0], 'en')[0])->toBe('Remaster');
});

test('a release kind switched off on the notifications says nothing of its moves', function () {
    $user = User::factory()->create(['preferences' => ['notifications_game_remasters' => false]]);

    refreshedGameFor($user, ['relatedReleases' => [storedRelatedRelease('5003', 'Breath of the Wild HD', GameKind::Remaster, '2027-01-15')]]);

    expect(newsOf($user))->toBe([]);
});

test('a release the user muted as a title of their own says nothing through the game', function () {
    $user = User::factory()->create(['preferences' => ['notify_related_announced' => true]]);
    $muted = MediaItem::factory()->game()->create(['provider_ref' => '5003', 'title' => 'Breath of the Wild HD']);
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $muted->id, 'mode' => FollowMode::Mute]);

    refreshedGameFor($user, ['relatedReleases' => [storedRelatedRelease('5003', 'Breath of the Wild HD', GameKind::Remaster, '2027-01-15')]]);

    expect(newsOf($user))->toBe([]);
});
