<?php

use App\Actions\Library\CreateList;
use App\Actions\Library\DropAttempt;
use App\Actions\Library\LogProgress;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Actions\Library\SetFavorite;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Library\StartRewatch;
use App\Actions\Library\SyncListMembership;
use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaItemSummaries;
use App\Enums\MediaUserStatus;
use App\Library\AttemptPolicy;
use App\Library\LibraryStates;
use App\Library\WatchedAt;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * The whole state of a position, every key in the order the client receives
 * it — what the pages and the API read once it is JSON. Each scenario names
 * only what differs from a position the user never touched, and the
 * comparison covers every key.
 */

const UNTOUCHED_STATE = [
    'watched' => false,
    'watchedAt' => null,
    'droppedAt' => null,
    'plays' => 0,
    'cycle' => 1,
    'completedCycles' => 0,
    'rating' => null,
    'favorite' => false,
    'listIds' => [],
    'follow' => null,
    'followVia' => null,
    'followSource' => null,
    'status' => null,
    'rootStatus' => null,
    'statusInherited' => false,
    'progress' => null,
    'playedAmount' => null,
    'droppedAmount' => null,
    'latestDropped' => false,
    'watchedEpisodes' => null,
    'airedEpisodes' => null,
    'remainingEpisodes' => null,
    'skippedEpisodes' => null,
    'remainingMinutes' => null,
    'watchedMinutes' => null,
    'position' => null,
    'nextEpisode' => null,
    'inProgress' => false,
    'progression' => true,
];

/**
 * @param  array<string, mixed>  $differences
 * @return array<string, mixed>
 */
function stateWith(array $differences): array
{
    return array_merge(UNTOUCHED_STATE, $differences);
}

/** @return array<string, array<string, mixed>> */
function encodedStates(User $user, MediaItem ...$items): array
{
    $states = app(LibraryStates::class)->resolve(
        $user,
        array_map(fn (MediaItem $item) => MediaItemSummaries::summary($item), $items),
    );

    return json_decode(json_encode($states, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function encodedState(User $user, MediaItem $item): array
{
    return encodedStates($user, $item)["{$item->type}:{$item->provider_ref}"];
}

function momentAt(string $moment): CarbonImmutable
{
    return CarbonImmutable::parse($moment);
}

function isoAt(string $moment): string
{
    return momentAt($moment)->toIso8601String();
}

/**
 * A show whose episodes carry the given air dates and runtimes, titled
 * "S1E2" and so on; the show declares 45 minutes an episode.
 *
 * @param  array<int, list<array{string, int|null}>>  $seasons  season number => [air date, runtime] per episode
 */
function pricedShow(array $seasons): MediaItem
{
    $show = MediaItem::factory()->show()->create(['runtime' => 45]);

    foreach ($seasons as $number => $episodes) {
        $season = MediaItem::factory()->season($show, $number)->create();

        foreach ($episodes as $index => [$airDate, $runtime]) {
            MediaItem::factory()->episode($season, $index + 1, $airDate)->create([
                'runtime' => $runtime,
                'title' => "S{$number}E".($index + 1),
            ]);
        }
    }

    return $show;
}

/** @return array<string, mixed> */
function pointerTo(MediaItem $show, int $season, int $episode, bool $withSeason): array
{
    return [
        'type' => 'episode',
        'ref' => "{$show->provider_ref}:{$season}:{$episode}",
        'title' => "S{$season}E{$episode}",
        'number' => $episode,
        'seasonNumber' => $withSeason ? $season : null,
    ];
}

test('a title the user never touched, persisted or not, reads the default state', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    $states = app(LibraryStates::class)->resolve($user, [
        new MediaSummary(type: 'movie', ref: '603', provider: 'tmdb', title: 'The Matrix'),
        MediaItemSummaries::summary($movie),
    ]);

    expect(json_decode(json_encode($states), true))->toBe([
        'movie:603' => UNTOUCHED_STATE,
        "movie:{$movie->provider_ref}" => UNTOUCHED_STATE,
    ]);
});

test('a film watched twice, rated, a favourite and on a list', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['runtime' => 120]);
    $list = app(CreateList::class)->execute($user, 'Noir');

    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(momentAt('2026-01-01 20:00')));
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(momentAt('2026-02-01 20:00')), null, AttemptPolicy::NewAttempt);
    app(RateItem::class)->execute($user, $movie, 8);
    app(SetFavorite::class)->execute($user, $movie, true);
    app(SyncListMembership::class)->execute($user, $movie, [$list->id]);

    expect(encodedState($user, $movie))->toBe(stateWith([
        'watched' => true,
        'watchedAt' => isoAt('2026-02-01 20:00'),
        'plays' => 2,
        'completedCycles' => 2,
        'rating' => 8,
        'favorite' => true,
        'listIds' => [$list->id],
        'followSource' => 'activity',
    ]));
});

test('a dropped film', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(DropAttempt::class)->execute($user, $movie, WatchedAt::at(momentAt('2026-03-01 21:00')));

    expect(encodedState($user, $movie))->toBe(stateWith([
        'droppedAt' => isoAt('2026-03-01 21:00'),
        'followSource' => 'dropped',
        'status' => 'dropped',
        'latestDropped' => true,
    ]));
});

test('a game being played, with its progress', function () {
    enableGamesModule();
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create(['runtime' => 600]);

    app(SetMediaStatus::class)->execute($user, $game, MediaUserStatus::InProgress);
    app(LogProgress::class)->execute($user, $game, 90, null);

    expect(encodedState($user, $game))->toBe(stateWith([
        'followSource' => 'activity',
        'status' => 'in_progress',
        'progress' => 90,
    ]));
});

test('a dropped game played again keeps the drop and its hours next to the open session', function () {
    enableGamesModule();
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    app(DropAttempt::class)->execute($user, $game, WatchedAt::at(momentAt('2026-03-02 10:00')), 120);
    app(LogProgress::class)->execute($user, $game, 30, null);

    expect(encodedState($user, $game))->toBe(stateWith([
        'droppedAt' => isoAt('2026-03-02 10:00'),
        // The open session is what the game is now, for the bell too.
        'followSource' => 'activity',
        'status' => 'in_progress',
        'progress' => 30,
        'playedAmount' => 120,
        'droppedAmount' => 120,
        'latestDropped' => true,
    ]));
});

test('a show under way, with a skipped episode, a skipped season, a watched special and one episode still to air', function () {
    $user = User::factory()->create();
    $show = pricedShow([
        0 => [['2019-12-01', 20]],
        1 => [['2020-01-01', 30], ['2020-01-08', null], ['2020-01-15', 30]],
        2 => [['2021-01-01', 30], ['2021-01-08', 30]],
        3 => [['2022-01-01', 30], ['2022-01-08', 30], ['2027-01-01', 30]],
    ]);

    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(momentAt('2026-01-01 20:00')));
    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 2), WatchedAt::at(momentAt('2026-01-02 20:00')));
    app(DropAttempt::class)->execute($user, episodeOf($show, 1, 3), WatchedAt::at(momentAt('2026-01-02 21:00')));
    app(DropAttempt::class)->execute($user, seasonOf($show, 2), WatchedAt::at(momentAt('2026-01-02 22:00')));
    app(MarkWatched::class)->execute($user, episodeOf($show, 0, 1), WatchedAt::at(momentAt('2026-01-03 20:00')));

    $episode = episodeOf($show, 1, 3);

    expect(encodedStates($user, $show, seasonOf($show, 0), seasonOf($show, 1), seasonOf($show, 2), seasonOf($show, 3), $episode))->toBe([
        "show:{$show->provider_ref}" => stateWith([
            'watchedAt' => isoAt('2026-01-02 20:00'),
            'plays' => 2,
            'followSource' => 'activity',
            'watchedEpisodes' => 2,
            'airedEpisodes' => 7,
            'remainingEpisodes' => 2,
            'skippedEpisodes' => 3,
            'remainingMinutes' => 60,
            // The episode without a runtime costs the show's 45 minutes.
            'watchedMinutes' => 75,
            'position' => 6,
            'nextEpisode' => pointerTo($show, 3, 1, withSeason: true),
            'inProgress' => true,
        ]),
        "season:{$show->provider_ref}:0" => stateWith([
            'watched' => true,
            'watchedAt' => isoAt('2026-01-03 20:00'),
            'plays' => 1,
            'completedCycles' => 1,
            'progression' => false,
        ]),
        "season:{$show->provider_ref}:1" => stateWith([
            'watched' => true,
            'watchedAt' => isoAt('2026-01-02 20:00'),
            'plays' => 2,
            'completedCycles' => 1,
            'watchedEpisodes' => 2,
            'airedEpisodes' => 3,
            'remainingEpisodes' => 0,
            'skippedEpisodes' => 1,
            'remainingMinutes' => 0,
            // Priced like the show prices it.
            'watchedMinutes' => 75,
        ]),
        "season:{$show->provider_ref}:2" => stateWith([
            'droppedAt' => isoAt('2026-01-02 22:00'),
            'status' => 'dropped',
            'watchedEpisodes' => 0,
            'airedEpisodes' => 2,
            'remainingEpisodes' => 0,
            'skippedEpisodes' => 2,
            'remainingMinutes' => 0,
            'watchedMinutes' => 0,
        ]),
        "season:{$show->provider_ref}:3" => stateWith([
            'watchedEpisodes' => 0,
            'airedEpisodes' => 2,
            'remainingEpisodes' => 2,
            'skippedEpisodes' => 0,
            'remainingMinutes' => 60,
            'watchedMinutes' => 0,
            'position' => 1,
            'nextEpisode' => pointerTo($show, 3, 1, withSeason: false),
            'inProgress' => true,
        ]),
        "episode:{$episode->provider_ref}" => stateWith([
            'droppedAt' => isoAt('2026-01-02 21:00'),
            'status' => 'dropped',
            'latestDropped' => true,
        ]),
    ]);
});

test('a dropped show: the season it stopped in wears the drop, an untouched one only names it', function () {
    $user = User::factory()->create();
    $show = pricedShow([1 => [['2020-01-01', 30], ['2020-01-08', 30]], 2 => [['2021-01-01', 30]]]);

    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(momentAt('2026-01-01 20:00')));
    app(DropAttempt::class)->execute($user, $show, WatchedAt::at(momentAt('2026-02-01 21:00')));

    expect(encodedStates($user, $show, seasonOf($show, 1), seasonOf($show, 2)))->toBe([
        "show:{$show->provider_ref}" => stateWith([
            'watchedAt' => isoAt('2026-01-01 20:00'),
            'droppedAt' => isoAt('2026-02-01 21:00'),
            'plays' => 1,
            'followSource' => 'dropped',
            'status' => 'dropped',
            'watchedEpisodes' => 1,
            'airedEpisodes' => 3,
            'remainingEpisodes' => 2,
            'skippedEpisodes' => 0,
            'remainingMinutes' => 60,
            'watchedMinutes' => 30,
            'position' => 2,
            'nextEpisode' => pointerTo($show, 1, 2, withSeason: true),
            'inProgress' => true,
        ]),
        "season:{$show->provider_ref}:1" => stateWith([
            'watchedAt' => isoAt('2026-01-01 20:00'),
            'droppedAt' => isoAt('2026-02-01 21:00'),
            'plays' => 1,
            'status' => 'dropped',
            'rootStatus' => 'dropped',
            'statusInherited' => true,
            'watchedEpisodes' => 1,
            'airedEpisodes' => 2,
            'remainingEpisodes' => 1,
            'skippedEpisodes' => 0,
            'remainingMinutes' => 30,
            'watchedMinutes' => 30,
            'position' => 2,
            'nextEpisode' => pointerTo($show, 1, 2, withSeason: false),
        ]),
        "season:{$show->provider_ref}:2" => stateWith([
            'rootStatus' => 'dropped',
            'watchedEpisodes' => 0,
            'airedEpisodes' => 1,
            'remainingEpisodes' => 1,
            'skippedEpisodes' => 0,
            'remainingMinutes' => 30,
            'watchedMinutes' => 0,
            'position' => 1,
            'nextEpisode' => pointerTo($show, 2, 1, withSeason: false),
        ]),
    ]);
});

test('a paused show: the season under way wears the pause without a date, a finished one keeps its completion', function () {
    $user = User::factory()->create();
    $show = pricedShow([1 => [['2020-01-01', 30], ['2020-01-08', 30]], 2 => [['2021-01-01', 30], ['2021-01-08', 30]]]);

    app(MarkWatched::class)->execute($user, seasonOf($show, 1), WatchedAt::at(momentAt('2026-01-01 20:00')));
    app(MarkWatched::class)->execute($user, episodeOf($show, 2, 1), WatchedAt::at(momentAt('2026-01-02 20:00')));
    app(SetMediaStatus::class)->execute($user, $show, MediaUserStatus::OnHold);

    expect(encodedStates($user, $show, seasonOf($show, 1), seasonOf($show, 2)))->toBe([
        "show:{$show->provider_ref}" => stateWith([
            'watchedAt' => isoAt('2026-01-02 20:00'),
            'plays' => 3,
            'followSource' => 'on_hold',
            'status' => 'on_hold',
            'watchedEpisodes' => 3,
            'airedEpisodes' => 4,
            'remainingEpisodes' => 1,
            'skippedEpisodes' => 0,
            'remainingMinutes' => 30,
            'watchedMinutes' => 90,
            'position' => 4,
            'nextEpisode' => pointerTo($show, 2, 2, withSeason: true),
            'inProgress' => true,
        ]),
        "season:{$show->provider_ref}:1" => stateWith([
            'watched' => true,
            'watchedAt' => isoAt('2026-01-01 20:00'),
            'plays' => 2,
            'completedCycles' => 1,
            'rootStatus' => 'on_hold',
            'watchedEpisodes' => 2,
            'airedEpisodes' => 2,
            'remainingEpisodes' => 0,
            'skippedEpisodes' => 0,
            'remainingMinutes' => 0,
            'watchedMinutes' => 60,
        ]),
        "season:{$show->provider_ref}:2" => stateWith([
            'watchedAt' => isoAt('2026-01-02 20:00'),
            'plays' => 1,
            'status' => 'on_hold',
            'rootStatus' => 'on_hold',
            'statusInherited' => true,
            'watchedEpisodes' => 1,
            'airedEpisodes' => 2,
            'remainingEpisodes' => 1,
            'skippedEpisodes' => 0,
            'remainingMinutes' => 30,
            'watchedMinutes' => 30,
            'position' => 2,
            'nextEpisode' => pointerTo($show, 2, 2, withSeason: false),
        ]),
    ]);
});

test('a show watched again: counters read the second cycle, plays the whole history', function () {
    $user = User::factory()->create();
    $show = pricedShow([1 => [['2020-01-01', 30], ['2020-01-08', 30]]]);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(momentAt('2025-01-01 20:00')));
    app(StartRewatch::class)->execute($user, $show);
    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(momentAt('2026-01-01 20:00')));

    $episode = episodeOf($show, 1, 1);

    expect(encodedStates($user, $show, seasonOf($show, 1), $episode))->toBe([
        "show:{$show->provider_ref}" => stateWith([
            'watchedAt' => isoAt('2026-01-01 20:00'),
            'plays' => 3,
            'cycle' => 2,
            'completedCycles' => 1,
            'followSource' => 'activity',
            'watchedEpisodes' => 1,
            'airedEpisodes' => 2,
            'remainingEpisodes' => 1,
            'skippedEpisodes' => 0,
            'remainingMinutes' => 30,
            'watchedMinutes' => 30,
            'position' => 2,
            'nextEpisode' => pointerTo($show, 1, 2, withSeason: true),
            'inProgress' => true,
        ]),
        "season:{$show->provider_ref}:1" => stateWith([
            'watchedAt' => isoAt('2026-01-01 20:00'),
            'plays' => 3,
            'cycle' => 2,
            'completedCycles' => 1,
            'watchedEpisodes' => 1,
            'airedEpisodes' => 2,
            'remainingEpisodes' => 1,
            'skippedEpisodes' => 0,
            'remainingMinutes' => 30,
            'watchedMinutes' => 30,
            'position' => 2,
            'nextEpisode' => pointerTo($show, 1, 2, withSeason: false),
            'inProgress' => true,
        ]),
        "episode:{$episode->provider_ref}" => stateWith([
            'watched' => true,
            'watchedAt' => isoAt('2026-01-01 20:00'),
            'plays' => 2,
            'cycle' => 2,
            'completedCycles' => 2,
        ]),
    ]);
});

test('a title rung by a followed person names the person', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['name' => 'Keanu Reeves']);
    $movie = MediaItem::factory()->movie()->create();

    Follow::factory()->for($user)->for($movie, 'mediaItem')->create()->viaPeople()->attach($person->id);

    expect(encodedState($user, $movie))->toBe(stateWith([
        'follow' => 'follow',
        'followVia' => 'Keanu Reeves',
        'followSource' => 'creator',
    ]));
});
