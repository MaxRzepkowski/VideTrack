<?php

use App\Actions\Library\DropAttempt;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\SyncListMembership;
use App\Catalog\Data\PlatformRelease;
use App\Catalog\DatePrecision;
use App\Catalog\GameKind;
use App\Catalog\ReleaseRegion;
use App\Enums\FollowMode;
use App\Library\WatchedAt;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use App\Tracking\Releases\ReleaseFeed;
use App\Tracking\Surface;
use Carbon\CarbonImmutable;

/*
 * Everything the release feed hands to the calendar, the countdowns and the
 * day's notifications, for one library holding each kind of release: films
 * in and out of the window, a show with a special and a skipped season, games
 * with platform dates and DLC, a book, a title a followed person rang, a muted
 * one. Every field of every entry, so a change to how the feed reads the
 * titles shows here first.
 */

/** A title with nothing left to chance: a factory would draw its name, year and ref. */
function goldenTitle(string $type, string $ref, array $attributes = []): MediaItem
{
    $factory = match ($type) {
        'movie' => MediaItem::factory()->movie(),
        'show' => MediaItem::factory()->show(),
        'game' => MediaItem::factory()->game(),
        'book' => MediaItem::factory()->book(),
    };

    return $factory->create([
        'provider_ref' => $ref,
        'title' => "{$type} {$ref}",
        'year' => 2026,
        'runtime' => $type === 'movie' ? 100 : null,
        'release_date' => null,
        ...$attributes,
    ]);
}

/** @param  array<int, list<string>>  $seasons  season number => episode air dates */
function goldenShow(string $ref, array $seasons): MediaItem
{
    $show = goldenTitle('show', $ref, ['release_date' => '2020-01-01']);

    foreach ($seasons as $number => $dates) {
        $season = MediaItem::factory()->season($show, $number)->create(['title' => "Season {$number}", 'year' => 2026, 'release_date' => $dates[0]]);

        foreach ($dates as $index => $date) {
            MediaItem::factory()->episode($season, $index + 1, $date)->create(['title' => "Episode {$number}x".($index + 1), 'year' => 2026, 'runtime' => 45]);
        }
    }

    return $show;
}

test('the release feed hands every surface the same entries, field by field', function () {
    enableGamesModule();
    enableBooksModule();

    $user = User::factory()->create(['preferences' => ['region' => 'PL']]);
    $day = fn (int $days): string => today()->addDays($days)->toDateString();
    $backlog = fn (MediaItem $title) => app(SyncListMembership::class)->execute($user, $title, [$user->watchlist()->id]);
    $watched = fn (MediaItem $item) => app(MarkWatched::class)->execute($user, $item, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    // Films on the Backlog: a premiere, a home release with its note, one day
    // for both, a premiere past with the home release ahead, one beyond any
    // window, one without dates.
    $backlog(goldenTitle('movie', '101', ['release_date' => $day(5)]));
    $backlog(goldenTitle('movie', '102', ['release_date' => $day(-60), 'home_release_date' => $day(10), 'metadata' => ['homeReleaseNote' => 'Netflix']]));
    $backlog(goldenTitle('movie', '103', ['release_date' => $day(3), 'home_release_date' => $day(3)]));
    $backlog(goldenTitle('movie', '104', ['release_date' => $day(-30), 'home_release_date' => $day(20)]));
    $backlog(goldenTitle('movie', '105', ['release_date' => $day(400)]));
    $backlog(goldenTitle('movie', '106'));

    // A film seen once, whose home release is ahead: the history row.
    $watched(goldenTitle('movie', '107', ['release_date' => $day(-90), 'home_release_date' => $day(12)]));

    // A film a followed person rang, and one the user muted.
    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => goldenTitle('movie', '108', ['release_date' => $day(8)])->id, 'mode' => FollowMode::Follow])
        ->viaPeople()->attach($person->id);
    $muted = goldenTitle('movie', '109', ['release_date' => $day(5)]);
    $backlog($muted);
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $muted->id, 'mode' => FollowMode::Mute]);

    // A show being watched: episodes ahead, a special, a skipped season.
    $show = goldenShow('201', [
        0 => [$day(6)],
        1 => ['2020-01-01', $day(2), $day(40)],
        2 => [$day(7)],
    ]);
    $watched(episodeOf($show, 1, 1));
    app(DropAttempt::class)->execute($user, seasonOf($show, 2), WatchedAt::releaseDateOrNow());

    // A show only on the Backlog.
    $backlog(goldenShow('202', [1 => [$day(15)]]));

    // A game with platform dates (one regional pair, one known only to its
    // quarter) and a DLC of its own; a game without platform data.
    $backlog(goldenTitle('game', '301', [
        'release_date' => $day(10),
        'metadata' => [
            'platformReleases' => [
                ['date' => $day(10), 'platform' => 'PS5', 'status' => null, 'region' => 'europe'],
                ['date' => $day(10), 'platform' => 'Switch', 'status' => null, 'region' => 'europe'],
                ['date' => $day(14), 'platform' => 'Switch', 'status' => null, 'region' => 'north_america'],
                ['date' => '2027-03-31', 'platform' => 'PC', 'status' => null, 'region' => 'worldwide', 'precision' => 'quarter'],
            ],
            'relatedReleases' => [
                storedRelatedRelease('3011', 'The Expansion', GameKind::Expansion, $day(30), [
                    new PlatformRelease($day(30), 'PC', null, ReleaseRegion::Worldwide),
                ]),
                storedRelatedRelease('3012', 'The Remaster', GameKind::Remaster, '2027-12-31', precision: DatePrecision::Year),
            ],
        ],
    ]));
    $backlog(goldenTitle('game', '302', ['release_date' => $day(9)]));

    // A book on the Backlog.
    $backlog(goldenTitle('book', '401', ['release_date' => $day(11), 'metadata' => ['pages' => 320, 'coverColor' => '#5d8798']]));

    $feed = app(ReleaseFeed::class);
    $user->refresh();

    expectGolden('release-feed', [
        'calendar' => $feed->entries($user, Surface::Calendar, $day(0), $day(60))->all(),
        'countdowns' => $feed->entries($user, Surface::Countdowns, $day(0), $day(800))->all(),
        'notifications on a busy day' => $feed->entries($user, Surface::Notifications, $day(3), $day(3))->all(),
        'notifications on a game\'s day' => $feed->entries($user, Surface::Notifications, $day(10), $day(10))->all(),
        'notifications on a quiet day' => $feed->entries($user, Surface::Notifications, $day(1), $day(1))->all(),
    ]);
});
