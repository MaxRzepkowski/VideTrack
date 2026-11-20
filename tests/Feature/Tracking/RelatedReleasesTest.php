<?php

use App\Actions\Library\DropAttempt;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Library\SyncListMembership;
use App\Catalog\Data\PlatformRelease;
use App\Catalog\DatePrecision;
use App\Catalog\GameKind;
use App\Catalog\ReleaseRegion;
use App\Enums\FollowMode;
use App\Enums\MediaUserStatus;
use App\Jobs\NotifyTodaysReleases;
use App\Library\WatchedAt;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\TaskRun;
use App\Models\User;
use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;
use App\Tracking\Releases\ReleaseEntry;
use App\Tracking\Releases\ReleaseFeed;
use App\Tracking\Surface;
use App\Tracking\Surfaces\FeedFilter;
use App\Tracking\Surfaces\IcsFeed;
use App\Tracking\Surfaces\TodaysReleases;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A game the user keeps on the Backlog, listing its releases of their own.
 *
 * @param  list<array<string, mixed>>  $related  as stored
 */
function backloggedGameWith(User $user, array $related, string $ref = '7346', string $title = 'Breath of the Wild'): MediaItem
{
    $game = MediaItem::factory()->game()->create([
        'provider_ref' => $ref,
        'title' => $title,
        'release_date' => '2017-03-03',
        'metadata' => ['relatedReleases' => $related],
    ]);

    app(SyncListMembership::class)->execute($user, $game, [$user->watchlist()->id]);

    return $game;
}

/** @return list<ReleaseEntry> */
function releasesOn(User $user, Surface $surface, ?string $to = null): array
{
    return app(ReleaseFeed::class)->entries($user->refresh(), $surface, today()->toDateString(), $to ?? today()->addYears(3)->toDateString())->all();
}

/**
 * @param  list<ReleaseEntry>  $entries
 * @return list<string> "ref date platform"
 */
function relatedLines(array $entries): array
{
    return array_map(fn (ReleaseEntry $entry): string => trim("{$entry->media->ref} {$entry->media->releaseDate} {$entry->media->platform}"), $entries);
}

beforeEach(fn () => enableGamesModule());

test('a tracked game\'s dlc, remake and remaster come to the calendar per platform, each naming the game', function () {
    $user = User::factory()->create(['preferences' => ['region' => 'PL']]);
    $game = backloggedGameWith($user, [
        storedRelatedRelease('5003', 'Breath of the Wild HD', GameKind::Remaster, today()->addDays(20)->toDateString(), [
            new PlatformRelease(today()->addDays(20)->toDateString(), 'Switch 2', null, ReleaseRegion::Europe),
            new PlatformRelease(today()->addDays(25)->toDateString(), 'Switch 2', null, ReleaseRegion::NorthAmerica),
            new PlatformRelease(today()->addDays(20)->toDateString(), 'PC', null, ReleaseRegion::Worldwide),
        ]),
        storedRelatedRelease('5004', 'Breath of the Wild Reimagined', GameKind::Remake, today()->addDays(40)->toDateString()),
        storedRelatedRelease('5000', 'The Master Trials', GameKind::Dlc, '2017-06-30'),
    ]);

    $entries = releasesOn($user, Surface::Calendar);

    expect(relatedLines($entries))->toBe([
        '5003 '.today()->addDays(20)->toDateString().' Switch 2, PC',
        '5004 '.today()->addDays(40)->toDateString(),
    ])
        ->and($entries[0]->media->type)->toBe('game')
        ->and($entries[0]->media->extra)->toMatchArray(['gameType' => 'remaster', 'relatedTo' => 'Breath of the Wild'])
        ->and($entries[0]->rootId)->toBe($game->id)
        ->and($entries[0]->isPart())->toBeFalse();
});

test('the countdowns take a release known only to its year, the calendar does not', function () {
    $user = User::factory()->create();
    backloggedGameWith($user, [storedRelatedRelease('5003', 'Breath of the Wild HD', GameKind::Remaster, '2027-12-31', precision: DatePrecision::Year)]);

    expect(relatedLines(releasesOn($user, Surface::Countdowns)))->toBe(['5003 2027-12-31'])
        ->and(releasesOn($user, Surface::Calendar))->toBe([]);
});

test('each kind answers to its own switch on each surface', function () {
    $user = User::factory()->create(['preferences' => ['calendar_game_addons' => false, 'countdown_game_remakes' => false]]);
    backloggedGameWith($user, [
        storedRelatedRelease('5000', 'The Master Trials', GameKind::Dlc, today()->addDays(5)->toDateString()),
        storedRelatedRelease('5001', 'Blood Dragon', GameKind::StandaloneExpansion, today()->addDays(6)->toDateString()),
        storedRelatedRelease('5004', 'Reimagined', GameKind::Remake, today()->addDays(7)->toDateString()),
    ]);

    expect(collect(releasesOn($user, Surface::Calendar))->map(fn (ReleaseEntry $entry): string => $entry->media->ref)->all())->toBe(['5004'])
        ->and(collect(releasesOn($user, Surface::Countdowns))->map(fn (ReleaseEntry $entry): string => $entry->media->ref)->all())->toBe(['5000', '5001']);
});

test('a release the user tracks or muted is left to that, and one two tracked games list comes once', function () {
    $user = User::factory()->create();
    $remake = storedRelatedRelease('5004', 'Reimagined', GameKind::Remake, today()->addDays(7)->toDateString());
    backloggedGameWith($user, [$remake, storedRelatedRelease('5000', 'The Master Trials', GameKind::Dlc, today()->addDays(5)->toDateString())]);
    backloggedGameWith($user, [$remake, storedRelatedRelease('5001', 'Muted Expansion', GameKind::Expansion, today()->addDays(6)->toDateString())], '7347', 'Majora');
    // The DLC is on the Backlog in its own right: its own entry, not the shelf's.
    backloggedGameWith($user, [], '5000', 'The Master Trials');
    $muted = MediaItem::factory()->game()->create(['provider_ref' => '5001', 'title' => 'Muted Expansion']);
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $muted->id, 'mode' => FollowMode::Mute]);

    $entries = releasesOn($user, Surface::Calendar);

    expect(array_map(fn (ReleaseEntry $entry): array => [$entry->media->ref, $entry->media->extra['relatedTo'] ?? null], $entries))
        ->toEqualCanonicalizing([['5004', 'Breath of the Wild']]);
});

test('a release the user dropped or put on hold as a title of their own does not come back through the game', function () {
    $user = User::factory()->create();
    backloggedGameWith($user, [
        storedRelatedRelease('5000', 'The Master Trials', GameKind::Dlc, today()->addDays(5)->toDateString()),
        storedRelatedRelease('5001', 'The Champions Ballad', GameKind::Expansion, today()->addDays(6)->toDateString()),
    ]);
    app(DropAttempt::class)->execute($user, MediaItem::factory()->game()->create(['provider_ref' => '5000', 'release_date' => today()->addDays(5)->toDateString()]), WatchedAt::releaseDateOrNow());
    app(SetMediaStatus::class)->execute($user, MediaItem::factory()->game()->create(['provider_ref' => '5001', 'release_date' => today()->addDays(6)->toDateString()]), MediaUserStatus::OnHold);

    // The defaults keep dropped titles off the countdowns, and on-hold ones off the notifications.
    expect(releasesOn($user, Surface::Countdowns))->toHaveCount(1)
        ->and(releasesOn($user, Surface::Countdowns)[0]->media->extra)->not->toHaveKey('relatedTo')
        ->and(releasesOn($user, Surface::Notifications))->toHaveCount(0);
});

test('a bell a followed creator rang on the release is no stance of the user\'s: the game still tells of it, once', function (bool $creatorsOnCalendar) {
    $user = User::factory()->create(['preferences' => ['calendar_creators' => $creatorsOnCalendar]]);
    backloggedGameWith($user, [storedRelatedRelease('5000', 'The Master Trials', GameKind::Dlc, today()->addDays(5)->toDateString())]);
    $dlc = MediaItem::factory()->game()->create(['provider_ref' => '5000', 'title' => 'The Master Trials', 'release_date' => today()->addDays(5)->toDateString()]);
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $dlc->id])->viaPeople()->attach(Person::factory()->create());

    $entries = releasesOn($user, Surface::Calendar);

    expect($entries)->toHaveCount(1)
        ->and(isset($entries[0]->media->extra['relatedTo']))->toBe(! $creatorsOnCalendar);
})->with(['the creators row off' => false, 'the creators row on' => true]);

test('the countdowns find a DLC by its game\'s name', function () {
    $user = User::factory()->create();
    backloggedGameWith($user, [storedRelatedRelease('5000', 'The Master Trials', GameKind::Dlc, today()->addDays(5)->toDateString())]);

    test()->actingAs($user)
        ->get(route('countdowns', ['q' => 'Breath of the Wild']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.0.media.ref', '5000'));
});

test('a muted game takes its releases with it', function () {
    $user = User::factory()->create();
    $game = backloggedGameWith($user, [storedRelatedRelease('5000', 'The Master Trials', GameKind::Dlc, today()->addDays(5)->toDateString())]);
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $game->id, 'mode' => FollowMode::Mute]);

    expect(releasesOn($user, Surface::Calendar))->toBe([]);
});

test('the subscription feed says what a release is and whose', function () {
    $user = User::factory()->create();
    backloggedGameWith($user, [storedRelatedRelease('5000', 'The Master Trials', GameKind::Dlc, today()->addDays(5)->toDateString())]);

    $ics = str_replace(["\r\n ", "\r\n"], ['', "\n"], app(IcsFeed::class)->render($user->refresh(), new FeedFilter));

    expect($ics)->toContain('SUMMARY:The Master Trials')
        ->toContain('UID:game-5000--'.today()->addDays(5)->toDateString().'@videtrack')
        ->toContain('DLC\nBreath of the Wild');
});

test('a dlc out today is a premiere, while premieres and its kind ping on the notifications', function () {
    $user = User::factory()->create();
    backloggedGameWith($user, [storedRelatedRelease('5000', 'The Master Trials', GameKind::Dlc, today()->toDateString())]);
    $run = fn () => (new NotifyTodaysReleases(TaskRun::queueTask('notify-releases')))->handle(app(TodaysReleases::class));

    $user->preferences = ['notifications_game_addons' => false];
    $user->save();
    $run();

    expect($user->notifications()->count())->toBe(0);

    $user->preferences = [];
    $user->save();
    $run();
    $event = MediaEventPayload::fromStored($user->notifications()->sole()->data);

    expect($event->type)->toBe(MediaEventType::Premiere)
        ->and($event->title())->toBe('The Master Trials')
        ->and($event->relatedCard()?->game)->toBe('Breath of the Wild');
});
