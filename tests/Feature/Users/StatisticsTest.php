<?php

use App\Catalog\ProgressUnit;
use App\Enums\MediaUserStatus;
use App\Library\RatingScale;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function statisticsUser(): User
{
    return User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw']]);
}

test('a year counts what was finished on the user\'s own calendar', function () {
    $user = statisticsUser();
    $movie = MediaItem::factory()->movie()->create(['runtime' => 100]);
    $other = MediaItem::factory()->movie()->create(['runtime' => 90]);

    // 23:30 UTC on New Year's Eve is already the next year in Warsaw.
    Play::factory()->for($user)->for($movie, 'mediaItem')->create(['watched_at' => '2024-12-31 23:30:00']);
    Play::factory()->for($user)->for($other, 'mediaItem')->create(['watched_at' => '2025-06-01 20:00:00']);
    projectLibrary($user);

    $this->actingAs($user)
        ->get(route('statistics', ['period' => 2025]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Statistics')
            ->where('page.period.kind', 'year')
            ->where('page.period.days', 365)
            ->where('page.titles.completed.total', 2)
            ->where('page.summary.activeDays', 2)
            ->where('page.tiles.movie.minutes', 190)
            ->where('page.years', [['year' => 2025, 'completions' => 2]])
            ->where('page.first.media.title', $movie->title)
            ->where('page.first.at', '2025-01-01T00:30:00+01:00'));
});

test('a dropped attempt makes a game one of the period\'s titles but is not something finished', function () {
    enableGamesModule();
    $user = statisticsUser();
    $dropped = MediaItem::factory()->game()->create();
    $finished = MediaItem::factory()->game()->create();

    Play::factory()->for($user)->for($dropped, 'mediaItem')->dropped()->create(['watched_at' => '2025-03-01 12:00:00', 'amount' => 120]);
    Play::factory()->for($user)->for($finished, 'mediaItem')->create(['watched_at' => '2025-04-01 12:00:00', 'amount' => 600]);
    projectLibrary($user);

    $this->actingAs($user)
        ->get(route('statistics', ['period' => 2025, 'type' => 'game']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.type', 'game')
            ->where('page.titles.titles.total', 2)
            ->where('page.titles.completed.total', 1)
            ->where('page.titles.dropped.total', 1)
            ->where('page.tiles.game.minutes', 720));
});

test('a show watched across years is finished in the year of its last episode', function () {
    $user = statisticsUser();
    $show = MediaItem::factory()->show()->create();
    $season = MediaItem::factory()->season($show, 1)->create();
    $first = MediaItem::factory()->episode($season, 1)->create();
    $second = MediaItem::factory()->episode($season, 2)->create();
    $movie = MediaItem::factory()->movie()->create();

    Play::factory()->for($user)->for($first, 'mediaItem')->create(['watched_at' => '2024-05-01 20:00:00']);
    Play::factory()->for($user)->for($second, 'mediaItem')->create(['watched_at' => '2025-05-01 20:00:00']);
    Play::factory()->for($user)->for($movie, 'mediaItem')->create(['watched_at' => '2025-05-02 20:00:00']);
    projectLibrary($user);

    $this->actingAs($user)
        ->get(route('statistics', ['period' => 2025]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.titles.titles.total', 2)
            // Both episodes aired and are watched: the show is finished on the day of its last one.
            ->where('page.titles.completed.byType', [['type' => 'show', 'count' => 1], ['type' => 'movie', 'count' => 1]]));
});

test('each title of the period ends it as its last mark, a show once and on hold from its state', function () {
    $user = statisticsUser();
    $show = MediaItem::factory()->show()->create();
    $season = MediaItem::factory()->season($show, 1)->create();
    $watched = MediaItem::factory()->episode($season, 1)->create();
    MediaItem::factory()->episode($season, 2)->create();
    $paused = MediaItem::factory()->movie()->create();
    $rewatched = MediaItem::factory()->movie()->create();

    // Two episodes' worth of one show, one of them still unwatched: in progress, and counted once.
    Play::factory()->for($user)->for($watched, 'mediaItem')->create(['watched_at' => '2025-02-01 20:00:00']);
    Play::factory()->for($user)->for($watched, 'mediaItem')->create(['watched_at' => '2025-02-02 20:00:00']);
    // Finished, then a second go dropped later the same year: the drop is the last mark.
    Play::factory()->for($user)->for($rewatched, 'mediaItem')->create(['watched_at' => '2025-03-01 20:00:00']);
    Play::factory()->for($user)->for($rewatched, 'mediaItem')->dropped()->create(['watched_at' => '2025-04-01 20:00:00']);
    MediaUserState::factory()->for($user)->for($paused, 'mediaItem')->create(['status' => MediaUserStatus::OnHold, 'updated_at' => '2025-05-01 10:00:00']);
    Rating::factory()->for($user)->for($show, 'mediaItem')->create(['rating' => 8]);
    Rating::factory()->for($user)->for($watched, 'mediaItem')->create(['rating' => 2]);
    projectLibrary($user);

    $this->actingAs($user)
        ->get(route('statistics', ['period' => 2025]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.titles.titles.total', 3)
            ->where('page.titles.inProgress.byType', [['type' => 'show', 'count' => 1]])
            ->where('page.titles.onHold.byType', [['type' => 'movie', 'count' => 1]])
            ->where('page.titles.dropped.byType', [['type' => 'movie', 'count' => 1]])
            ->where('page.titles.completed.total', 0)
            // The tiles count the same titles: the paused film has no entry in the year, yet is one of its films.
            ->where('page.tiles.show.titles', 1)
            ->where('page.tiles.movie.titles', 2)
            // One rating per title: the episode's own rating is not the show's.
            ->where('page.summary.ratings', 1)
            ->where('page.summary.averageRating', 8)
            // One rating of 8 on the scale's histogram.
            ->where('page.summary.ratingCounts', array_replace(array_fill(0, RatingScale::MAX - RatingScale::MIN + 1, 0), [8 - RatingScale::MIN => 1])));

    // Put on hold after 2024 ended: nothing of it in 2024.
    $this->actingAs($user)
        ->get(route('statistics', ['period' => 2024]))
        ->assertInertia(fn (Assert $page) => $page->where('page.titles.titles.total', 0));
});

test('reading counts as time at the reader\'s own pace, said to be an estimate', function () {
    enableBooksModule();
    $user = User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw', 'reading_pace_pages' => 30]]);
    $book = MediaItem::factory()->book()->create();
    $movie = MediaItem::factory()->movie()->create(['runtime' => 100]);

    Play::factory()->for($user)->for($book, 'mediaItem')->create(['watched_at' => '2025-03-01 12:00:00', 'amount' => 90]);
    Play::factory()->for($user)->for($movie, 'mediaItem')->create(['watched_at' => '2025-03-02 12:00:00']);
    projectLibrary($user);

    // 90 pages at 30 an hour: three hours, ranked above the film's 100 minutes.
    $this->actingAs($user)
        ->get(route('statistics', ['period' => 2025]))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('activity.watchMinutes', 100)
                ->where('activity.readingMinutes', 180)
                ->where('taste.readingEstimated', true)
                ->where('taste.top.0.media.title', $book->title)
                ->where('taste.top.0.amount', 180)
                ->where('taste.top.0.estimatedMinutes', 180)
                ->where('taste.top.1.estimatedMinutes', null)))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.summary.time.total', 280)
            ->where('page.summary.readingMinutes', 180)
            ->where('page.tiles.book.minutes', 90)
            ->where('page.tiles.book.estimatedMinutes', 180)
            ->where('page.tiles.movie.estimatedMinutes', null));

    // One type that is read keeps its pages, the estimate beside them.
    $this->actingAs($user)
        ->get(route('statistics', ['period' => 2025, 'type' => 'book']))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('taste.readIn', ProgressUnit::Pages->value)
                ->where('taste.readingEstimated', false)
                ->where('taste.top.0.amount', 90)
                ->where('taste.top.0.estimatedMinutes', 180)));
});

test('a range of days counts only what falls between them, both included', function () {
    $user = statisticsUser();

    foreach (['2025-03-11 12:00:00', '2025-03-12 12:00:00', '2025-03-20 12:00:00', '2025-03-21 12:00:00'] as $moment) {
        Play::factory()->for($user)->for(MediaItem::factory()->movie(), 'mediaItem')->create(['watched_at' => $moment]);
    }

    projectLibrary($user);

    $this->actingAs($user)
        ->get(route('statistics', ['from' => '2025-03-20', 'to' => '2025-03-12']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.period.kind', 'range')
            ->where('page.period.from', '2025-03-12')
            ->where('page.period.to', '2025-03-20')
            ->where('page.period.days', 9)
            ->where('page.titles.titles.total', 2));
});

test('the whole history starts at what was first finished', function () {
    $user = statisticsUser();
    Play::factory()->for($user)->for(MediaItem::factory()->movie(), 'mediaItem')->create(['watched_at' => '2026-10-01 12:00:00']);
    projectLibrary($user);

    $this->actingAs($user)
        ->get(route('statistics', ['period' => 'all']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.period.kind', 'all')
            ->where('page.period.from', '2026-10-01')
            ->where('page.period.days', 10)
            ->where('page.allTime', 1));
});

test('an address that no longer makes sense falls back to the current year and every type', function () {
    $user = statisticsUser();

    $this->actingAs($user)
        ->get(route('statistics', ['period' => 'soon', 'type' => 'season', 'from' => '2025-02-30', 'to' => '2025-03-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.period.kind', 'year')
            ->where('page.period.year', 2026)
            ->where('page.type', null)
            ->where('page.titles.titles.total', 0)
            ->where('page.first', null));
});

test('the ratings of the period are the user\'s ratings of its titles', function () {
    $user = statisticsUser();
    $rated = MediaItem::factory()->movie()->create(['community_rating' => 8.0]);
    $other = MediaItem::factory()->movie()->create(['community_rating' => 6.0]);

    Play::factory()->for($user)->for($rated, 'mediaItem')->create(['watched_at' => '2025-02-01 12:00:00']);
    Play::factory()->for($user)->for($other, 'mediaItem')->create(['watched_at' => '2025-02-02 12:00:00']);
    Rating::factory()->for($user)->for($rated, 'mediaItem')->create(['rating' => 7]);
    projectLibrary($user);

    $this->actingAs($user)
        ->get(route('statistics', ['period' => 2025, 'type' => 'movie']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.summary.ratings', 1)
            ->where('page.summary.averageRating', 7)
            ->where('page.summary.communityDelta', -1));
});

test('activity spreads a year over its months, weekdays and hours on the user\'s clock', function () {
    $user = statisticsUser();

    // Monday 2025-03-03 21:30 in Warsaw (20:30 UTC), twice; one film in June.
    Play::factory()->for($user)->for(MediaItem::factory()->movie(), 'mediaItem')->create(['watched_at' => '2025-03-03 20:30:00']);
    Play::factory()->for($user)->for(MediaItem::factory()->movie(), 'mediaItem')->create(['watched_at' => '2025-03-03 20:45:00']);
    Play::factory()->for($user)->for(MediaItem::factory()->movie(), 'mediaItem')->create(['watched_at' => '2025-06-07 10:00:00']);
    // An episode from another year: its type gets an empty chart, not none.
    $show = MediaItem::factory()->show()->create();
    $season = MediaItem::factory()->season($show, 1)->create();
    Play::factory()->for($user)->for(MediaItem::factory()->episode($season, 1), 'mediaItem')->create(['watched_at' => '2024-02-01 10:00:00']);
    projectLibrary($user);

    $this->actingAs($user)
        ->get(route('statistics', ['period' => 2025]))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('activity')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('activity.buckets', array_map(fn (int $month): string => sprintf('2025-%02d', $month), range(1, 12)))
                ->where('activity.series.0.type', 'show')
                ->where('activity.series.0.counts', array_fill(0, 12, 0))
                ->where('activity.series.1.type', 'movie')
                ->where('activity.series.1.counts', [0, 0, 2, 0, 0, 1, 0, 0, 0, 0, 0, 0])
                ->count('activity.series', 2)
                ->where('activity.completions', 3)
                ->where('activity.weekdays.0', 2)
                ->where('activity.hours.21', 2)
                ->where('activity.busiestDay', ['day' => '2025-03-03', 'count' => 2])
                ->where('activity.days', ['2025-03-03' => 2, '2025-06-07' => 1])
                ->where('activity.months', null)));
});

test('a month at most is drawn day by day, a quarter week by week from the user\'s first weekday', function () {
    $user = User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw', 'week_start' => 'monday']]);
    Play::factory()->for($user)->for(MediaItem::factory()->movie(), 'mediaItem')->create(['watched_at' => '2025-03-05 12:00:00']);
    Play::factory()->for($user)->for(MediaItem::factory()->movie(), 'mediaItem')->create(['watched_at' => '2025-03-06 12:00:00']);
    projectLibrary($user);

    // Ten days: a column each, and no calendar of the same days below.
    $this->actingAs($user)
        ->get(route('statistics', ['from' => '2025-03-01', 'to' => '2025-03-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('activity.bucket', 'day')
                ->count('activity.buckets', 10)
                ->where('activity.buckets.4', '2025-03-05')
                ->where('activity.series.0.counts.4', 1)
                ->where('activity.days', null)
                ->where('activity.months', null)));

    // Sixty days from a Saturday: weeks named by their Monday, the first one reaching back before the range.
    $this->actingAs($user)
        ->get(route('statistics', ['from' => '2025-03-01', 'to' => '2025-04-29']))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('activity.bucket', 'week')
                ->where('activity.buckets.0', '2025-02-24')
                ->where('activity.buckets.1', '2025-03-03')
                ->where('activity.series.0.counts.1', 2)
                ->where('activity.buckets.9', '2025-04-28')
                ->count('activity.buckets', 10)));
});

test('a period longer than two years is drawn by years, its days as months', function () {
    $user = statisticsUser();
    Play::factory()->for($user)->for(MediaItem::factory()->movie(), 'mediaItem')->create(['watched_at' => '2020-05-01 12:00:00']);
    Play::factory()->for($user)->for(MediaItem::factory()->movie(), 'mediaItem')->create(['watched_at' => '2026-02-01 12:00:00']);
    projectLibrary($user);

    $this->actingAs($user)
        ->get(route('statistics', ['period' => 'all']))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('activity.buckets', ['2020', '2021', '2022', '2023', '2024', '2025', '2026'])
                ->where('activity.series.0.counts', [1, 0, 0, 0, 0, 0, 1])
                ->where('activity.days', null)
                ->where('activity.months', ['2020-05' => 1, '2026-02' => 1])));
});

test('taste ranks titles by time, lays their release years out and sums up their genres', function () {
    $user = statisticsUser();
    $long = MediaItem::factory()->movie()->create(['runtime' => 180, 'year' => 1999, 'metadata' => ['genres' => ['Drama', 'Crime']]]);
    $short = MediaItem::factory()->movie()->create(['runtime' => 90, 'year' => 2024, 'metadata' => ['genres' => ['Drama']]]);

    Play::factory()->for($user)->for($long, 'mediaItem')->create(['watched_at' => '2025-02-01 20:00:00']);
    Play::factory()->for($user)->for($short, 'mediaItem')->create(['watched_at' => '2025-02-02 20:00:00']);
    Rating::factory()->for($user)->for($short, 'mediaItem')->create(['rating' => 8]);
    projectLibrary($user);

    $this->actingAs($user)
        ->get(route('statistics', ['period' => 2025, 'type' => 'movie']))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('taste')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('taste.readIn', null)
                ->where('taste.top.0.media.title', $long->title)
                ->where('taste.top.0.amount', 180)
                ->where('taste.top.0.parts', null)
                ->where('taste.top.1.amount', 90)
                ->has('taste.releases', 2)
                ->where('taste.genres.0', ['name' => 'Drama', 'titles' => 2, 'amount' => 270, 'averageRating' => 8, 'rated' => 1])
                ->where('taste.genres.1.name', 'Crime')
                ->where('taste.lengths.0.type', 'movie')
                ->where('taste.lengths.0.average', 135)
                ->where('taste.lengths.0.total', 270)
                ->where('taste.lengths.0.longestRuntime', 180)));
});
