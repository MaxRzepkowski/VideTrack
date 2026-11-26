<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Media\BuildWatchStats;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\User;
use Carbon\CarbonImmutable;

test('stats are zeroed for every root type when nothing was played', function () {
    $user = User::factory()->create();

    $stats = app(BuildWatchStats::class)->execute($user);

    expect($stats)->toHaveKeys(['movie', 'show'])
        ->and((array) $stats['movie'])->toBe(['amount' => 0, 'watched' => 0, 'plays' => 0, 'titles' => 0, 'estimatedMinutes' => null]);
});

test('watch time counts every play and titles count distinct roots', function () {
    $user = User::factory()->create();

    $movie = MediaItem::factory()->movie()->create(['runtime' => 100]);
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-02-01')));

    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $show->children()->firstOrFail()->children()->get()
        ->each(fn (MediaItem $episode) => $episode->update(['runtime' => 30]));
    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-03-01')));

    $other = makeShowTree([1 => ['2020-01-01']]);
    $episode = $other->children()->firstOrFail()->children()->firstOrFail();
    $episode->update(['runtime' => 45]);
    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-04-01')));

    $stats = app(BuildWatchStats::class)->execute($user);

    expect((array) $stats['movie'])->toBe(['amount' => 200, 'watched' => 1, 'plays' => 2, 'titles' => 1, 'estimatedMinutes' => null])
        ->and((array) $stats['show'])->toBe(['amount' => 105, 'watched' => 3, 'plays' => 3, 'titles' => 2, 'estimatedMinutes' => null]);
});

test('an episode with no runtime is priced at the length its show declares', function () {
    $user = User::factory()->create();

    // TMDB leaves whole runs of daily and reality shows without an episode
    // runtime; counted at zero those hours would vanish from the tile in
    // silence, so the show's own declared episode length stands in — the same
    // price the progress bars quote.
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $show->update(['runtime' => 42]);
    $episodes = $show->children()->firstOrFail()->children()->orderBy('number')->get();
    $episodes[0]->update(['runtime' => 30]);
    $episodes[1]->update(['runtime' => null]);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-03-01')));

    expect(app(BuildWatchStats::class)->execute($user)['show']->amount)->toBe(72);
});

test('a watched special counts into the stats like any other episode', function () {
    $user = User::factory()->create();

    // Specials carry no progression of their own (no bar, never "in progress"
    // — see LibraryStateCalculator), but the hours are really watched: they belong
    // in the tiles, in the play tally and in the title count.
    $show = makeShowTree([0 => ['2019-12-01'], 1 => ['2020-01-01']]);
    $special = $show->children()->where('number', 0)->firstOrFail()->children()->firstOrFail();
    $special->update(['runtime' => 30]);

    app(MarkWatched::class)->execute($user, $special, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    expect((array) app(BuildWatchStats::class)->execute($user)['show'])
        ->toBe(['amount' => 30, 'watched' => 1, 'plays' => 1, 'titles' => 1, 'estimatedMinutes' => null]);
});

test('stats never mix users', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $movie = MediaItem::factory()->movie()->create(['runtime' => 90]);
    app(MarkWatched::class)->execute($other, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    expect(app(BuildWatchStats::class)->execute($user)['movie']->plays)->toBe(0);
});
