<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Media\BuildWatchStats;
use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Enums\ProgressSort;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use App\Users\Data\ActivityEntry;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('the history panel lists individual plays with timestamps', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-02-01 21:30')));

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/UserActivity')
            ->where('panel', 'history')
            ->where('isOwner', true)
            ->has('entries', 2)
            ->where('entries.0.at', '2026-02-01T21:30:00+00:00')
            ->where('entries.0.media.title', $movie->title));
});

test('episode cards in the history fall back to the season poster', function () {
    $user = User::factory()->create();
    $show = MediaItem::factory()->show()->create(['poster_path' => '/show.jpg']);
    $season = MediaItem::factory()->season($show, 1)->create(['poster_path' => '/season1.jpg']);
    $episode = MediaItem::factory()->episode($season, 1)->create(['poster_path' => null]);

    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.media.posterPath', '/season1.jpg')
            ->where('entries.0.media.extra.showTitle', $show->title));
});

test('the ratings panel lists rated positions', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    $user->ratings()->create(['media_item_id' => $movie->id, 'rating' => 8]);

    $this->actingAs($user)
        ->get(route('users.ratings', ['user' => $user->username]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/UserActivity')
            ->where('panel', 'ratings')
            ->has('entries', 1)
            ->where('entries.0.rating', 8));
});

test('the progress panel shows shows in the middle of watching', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $firstEpisode = MediaItem::query()->where('type', 'episode')->orderBy('number')->first();

    app(MarkWatched::class)->execute($user, $firstEpisode, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $this->actingAs($user)
        ->get(route('users.progress', ['user' => $user->username]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/UserProgress')
            ->has('entries', 1)
            ->where('entries.0.media.ref', $show->provider_ref)
            ->where('entries.0.watchedEpisodes', 1)
            ->where('entries.0.airedEpisodes', 2)
            ->where('entries.0.nextEpisode.number', 2));
});

test('the progress panel puts the freshest first whatever zone each entry is written in', function () {
    // Shows carry their moment in the app's zone, games in UTC: as strings
    // "14:00+01:00" would beat "13:30Z" (14:30 here).
    instanceTimezone('Europe/Warsaw');
    enableGamesModule();

    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $first = MediaItem::query()->where('type', 'episode')->orderBy('number')->first();
    app(MarkWatched::class)->execute($user, $first, WatchedAt::at(CarbonImmutable::parse('2026-01-01 14:00')));

    $game = MediaItem::factory()->game()->create();
    $this->travelTo(CarbonImmutable::parse('2026-01-01 14:30'));
    app(SetMediaStatus::class)->execute($user, $game, MediaUserStatus::InProgress);

    $this->actingAs($user)
        ->get(route('users.progress', ['user' => $user->username, 'sort' => ProgressSort::Activity->value, 'dir' => 'asc']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 2)
            ->where('entries.0.media.ref', $game->provider_ref)
            ->where('entries.1.media.ref', $show->provider_ref));
});

test('the progress panel searches, filters by type and sorts', function () {
    $user = User::factory()->create();

    $alpha = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $alpha->update(['title' => 'Alpha Show']);
    $zulu = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $zulu->update(['title' => 'Zulu Show']);

    foreach ([$alpha, $zulu] as $show) {
        $first = MediaItem::query()
            ->where('type', 'episode')
            ->whereIn('parent_id', MediaItem::query()->where('parent_id', $show->id)->pluck('id'))
            ->orderBy('number')
            ->first();

        app(MarkWatched::class)->execute($user, $first, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    }

    // Both shows, alphabetically.
    $this->actingAs($user)
        ->get(route('users.progress', ['user' => $user->username, 'sort' => 'title']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 2)
            ->where('entries.0.media.title', 'Alpha Show')
            ->where('filters.sort', 'title')
            ->where('types.0.type', 'show')
            ->where('types.0.count', 2));

    // The search reaches the show behind the episode card.
    $this->actingAs($user)
        ->get(route('users.progress', ['user' => $user->username, 'q' => 'zulu']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.title', 'Zulu Show'));

    // A type that is not on the shelves is no option at all — "All" stands.
    $this->actingAs($user)
        ->get(route('users.progress', ['user' => $user->username, 'type' => 'game']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('entries', 2)->where('filters.type', null));
});

test('the history grid sorts by title with visible pagination totals', function () {
    $user = User::factory()->create();
    $alpha = MediaItem::factory()->movie()->create(['title' => 'Alpha']);
    $zulu = MediaItem::factory()->movie()->create(['title' => 'Zulu']);

    app(MarkWatched::class)->execute($user, $zulu, WatchedAt::at(CarbonImmutable::parse('2026-01-02 20:00')));
    app(MarkWatched::class)->execute($user, $alpha, WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));

    $this->actingAs($user)
        ->get(route('users.history', [
            'user' => $user->username, 'group' => 'grid', 'sort' => 'title', 'dir' => 'asc',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'title')
            ->where('filters.group', 'grid')
            ->where('entries.0.media.title', 'Alpha')
            ->where('entries.1.media.title', 'Zulu')
            ->where('pagination.total', 2)
            ->where('pagination.totalPages', 1));
});

test('history filters by item type and by the root genre', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['metadata' => ['genres' => ['Drama']]]);
    $show = makeShowTree([1 => ['2020-01-01']]);
    $show->update(['metadata' => ['genres' => ['Comedy']]]);
    $episode = MediaItem::query()->where('type', 'episode')->firstOrFail();

    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));
    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-01-02 20:00')));

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'type' => 'movie']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.type', 'movie'));

    // The genre lives on the show root, two levels above the played episode.
    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'genre' => 'Comedy']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.type', 'episode')
            ->where('genreOptions', ['Comedy', 'Drama']));
});

test('the title search narrows the history to matching items (case-insensitive)', function () {
    $user = User::factory()->create();
    $matrix = MediaItem::factory()->movie()->create(['title' => 'The Matrix']);
    $inception = MediaItem::factory()->movie()->create(['title' => 'Inception']);

    app(MarkWatched::class)->execute($user, $matrix, WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));
    app(MarkWatched::class)->execute($user, $inception, WatchedAt::at(CarbonImmutable::parse('2026-01-02 20:00')));

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'q' => 'matrix']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', 'matrix')
            ->has('entries', 1)
            ->where('entries.0.media.title', 'The Matrix'));
});

test('the title search matches an ancestor title (a show name finds its watched episode)', function () {
    $user = User::factory()->create();
    $show = MediaItem::factory()->show()->create(['title' => 'Breaking Bad']);
    $season = MediaItem::factory()->season($show, 1)->create();
    $episode = MediaItem::factory()->episode($season, 1)->create(['title' => 'Pilot']);

    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'q' => 'breaking']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.title', 'Pilot'));
});

test('history filters by play outcome (completed / dropped)', function () {
    $user = User::factory()->create();
    $completed = MediaItem::factory()->movie()->create();
    $dropped = MediaItem::factory()->movie()->create();

    app(MarkWatched::class)->execute($user, $completed, WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'movie', 'ref' => $dropped->provider_ref]), ['status' => 'dropped', 'amount' => null])
        ->assertSuccessful();

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'status' => 'completed']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'completed')
            ->has('entries', 1)
            ->where('entries.0.media.ref', $completed->provider_ref)
            ->where('entries.0.outcome', 'completed'));

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'status' => 'dropped']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.ref', $dropped->provider_ref)
            ->where('entries.0.outcome', 'dropped'));

    // The status filter is history-only: the ratings tab ignores it.
    $this->actingAs($user)
        ->get(route('users.ratings', ['user' => $user->username, 'status' => 'dropped']))
        ->assertInertia(fn (Assert $page) => $page->where('filters.status', null));
});

test('the hide rated toggle excludes rated titles from the history', function () {
    $user = User::factory()->create();
    $rated = MediaItem::factory()->movie()->create();
    $plain = MediaItem::factory()->movie()->create();

    app(MarkWatched::class)->execute($user, $rated, WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));
    app(MarkWatched::class)->execute($user, $plain, WatchedAt::at(CarbonImmutable::parse('2026-01-02 20:00')));
    $user->ratings()->create(['media_item_id' => $rated->id, 'rating' => 7]);

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'hide_rated' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.ref', $plain->provider_ref));
});

test('filtering the history by a container type rolls the plays up into it', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08'], 2 => ['2021-01-01']]);
    $movie = MediaItem::factory()->movie()->create();

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-01-05 20:00')));
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-06 20:00')));

    // Shows: one card for the whole three-episode tree, dated by its latest
    // play — and the movie left out.
    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'type' => 'show']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('dayTotals', [])
            ->has('entries', 1)
            ->where('entries.0.id', null)
            ->where('entries.0.at', '2026-01-05T20:00:00+00:00')
            ->where('entries.0.media.type', 'show')
            ->where('entries.0.media.ref', $show->provider_ref));

    // Seasons roll up one level lower: both of them, once each. Season titles
    // are random here, so the assertion keys on the season itself.
    $seasons = collect(
        $this->actingAs($user)
            ->get(route('users.history', ['user' => $user->username, 'type' => 'season', 'group' => 'grid']))
            ->viewData('page')['props']['entries']
    )->keyBy(fn (ActivityEntry $entry): string => $entry->media->ref);

    expect($seasons)->toHaveCount(2)
        ->and($seasons)->toHaveKey("{$show->provider_ref}:2")
        ->and($seasons["{$show->provider_ref}:1"]->media->type)->toBe('season')
        ->and($seasons["{$show->provider_ref}:1"]->media->extra['showTitle'])->toBe($show->title);

    // Leaf types keep listing plays one by one.
    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'type' => 'episode']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 3)
            ->where('entries.0.media.type', 'episode'));
});

test('a dropped show is one rolled-up card together with the episodes watched before it', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $episode = MediaItem::query()->where('type', 'episode')->firstOrFail();

    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'show', 'ref' => $show->provider_ref]), ['status' => 'dropped', 'amount' => null])
        ->assertSuccessful();

    // The drop sits on the show, the play on an episode: rolled up they are
    // one and the same card, dated by the later of the two.
    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'type' => 'show']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.ref', $show->provider_ref));
});

test('hide rated judges the show when the history is rolled up to shows', function () {
    $user = User::factory()->create();
    $rated = makeShowTree([1 => ['2020-01-01']]);
    $unrated = makeShowTree([1 => ['2020-01-01']]);

    app(MarkWatched::class)->execute($user, $rated, WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));
    app(MarkWatched::class)->execute($user, $unrated, WatchedAt::at(CarbonImmutable::parse('2026-01-02 20:00')));
    $user->ratings()->create(['media_item_id' => $rated->id, 'rating' => 9]);

    // The rating sits on the show, never on the episodes below it — the flat
    // listing cannot see it, the rolled-up one can.
    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'type' => 'show', 'hide_rated' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.ref', $unrated->provider_ref));

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'hide_rated' => 1]))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 2));
});

test('a rolled-up grid obeys the sort mechanism in both directions', function () {
    $user = User::factory()->create();

    // Watched newest-title-first, so alphabetical order can only come from
    // the sort mechanism, never from the order the plays were logged in.
    foreach (['Riverdale' => '2026-01-03', 'Killing Eve' => '2026-01-02', 'Andor' => '2026-01-01'] as $title => $day) {
        $show = makeShowTree([1 => ['2020-01-01']]);
        $show->update(['title' => $title]);
        app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse("{$day} 20:00")));
    }

    $titles = fn (array $query) => collect(
        $this->actingAs($user)
            ->get(route('users.history', ['user' => $user->username, 'type' => 'show', 'group' => 'grid', ...$query]))
            ->viewData('page')['props']['entries']
    )->pluck('media.title')->all();

    expect($titles(['sort' => 'title', 'dir' => 'asc']))->toBe(['Andor', 'Killing Eve', 'Riverdale'])
        ->and($titles(['sort' => 'title', 'dir' => 'desc']))->toBe(['Riverdale', 'Killing Eve', 'Andor'])
        // The default: latest activity first.
        ->and($titles([]))->toBe(['Riverdale', 'Killing Eve', 'Andor'])
        ->and($titles(['sort' => 'activity', 'dir' => 'asc']))->toBe(['Andor', 'Killing Eve', 'Riverdale']);
});

test('a rolled-up grid sorts by the viewer\'s rating and by when the plays were logged', function () {
    $user = User::factory()->create();

    // Watched, logged and rated in three different orders, so each sort can
    // only come from its own key.
    foreach ([
        'Riverdale' => ['watched' => '2026-01-03', 'logged' => '2026-03-02', 'rating' => 5],
        'Killing Eve' => ['watched' => '2026-01-02', 'logged' => '2026-03-01', 'rating' => 9],
        'Andor' => ['watched' => '2026-01-01', 'logged' => '2026-03-03', 'rating' => null],
    ] as $title => $facts) {
        $show = makeShowTree([1 => ['2020-01-01']]);
        $show->update(['title' => $title]);
        $this->travelTo(CarbonImmutable::parse("{$facts['logged']} 09:00"));
        app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse("{$facts['watched']} 20:00")));

        if ($facts['rating'] !== null) {
            Rating::factory()->for($user)->for($show, 'mediaItem')->create(['rating' => $facts['rating']]);
        }
    }

    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00'));

    $titles = fn (array $query) => collect(
        $this->actingAs($user)
            ->get(route('users.history', ['user' => $user->username, 'type' => 'show', 'group' => 'grid', ...$query]))
            ->viewData('page')['props']['entries']
    )->pluck('media.title')->all();

    expect($titles(['sort' => 'my_rating', 'dir' => 'desc']))->toBe(['Killing Eve', 'Riverdale', 'Andor'])
        // The unrated go last whichever way the rating runs.
        ->and($titles(['sort' => 'my_rating', 'dir' => 'asc']))->toBe(['Riverdale', 'Killing Eve', 'Andor'])
        ->and($titles(['sort' => 'added', 'dir' => 'asc']))->toBe(['Killing Eve', 'Riverdale', 'Andor'])
        ->and($titles(['sort' => 'added', 'dir' => 'desc']))->toBe(['Andor', 'Riverdale', 'Killing Eve']);
});

test('rolled-up cards group by the day of their latest play', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $episodes = MediaItem::query()->where('type', 'episode')->orderBy('number')->get();
    $other = makeShowTree([1 => ['2020-01-01']]);

    app(MarkWatched::class)->execute($user, $episodes[0], WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));
    app(MarkWatched::class)->execute($user, $episodes[1], WatchedAt::at(CarbonImmutable::parse('2026-02-05 20:00')));
    app(MarkWatched::class)->execute($user, $other, WatchedAt::at(CarbonImmutable::parse('2026-02-05 21:00')));

    // The first show spans two days; it sits under the later one, once.
    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'type' => 'show']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 2)
            ->where('entries.0.day', '2026-02-05')
            ->where('entries.1.day', '2026-02-05')
            ->where('pagination.total', 2)
            // Days carry no totals here — a show is not a cost.
            ->where('dayTotals', []));
});

test('rolled-up pagination counts containers, and the search still climbs the tree', function () {
    $user = User::factory()->create();

    foreach (range(1, 3) as $index) {
        $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
        $show->update(['title' => "Rolled Show {$index}"]);
        app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse("2026-01-0{$index} 20:00")));
    }

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'type' => 'show']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 3)
            ->where('pagination.total', 3)
            ->where('pagination.totalPages', 1));

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'type' => 'show', 'q' => 'Rolled Show 2']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.title', 'Rolled Show 2'));
});

test('grouped-by-day pagination counts days, not items', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    foreach (range(1, 12) as $day) {
        app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse(sprintf('2026-03-%02d 20:00', $day))));
    }

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.group', 'day')
            ->has('entries', 10)
            ->where('entries.0.day', '2026-03-12')
            ->where('pagination.currentPage', 1)
            ->where('pagination.totalPages', 2)
            ->where('pagination.total', 12));

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 2)
            ->where('entries.0.day', '2026-03-02')
            ->where('pagination.currentPage', 2));
});

test('each day of the history carries what it cost', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['runtime' => 90]);
    $abandoned = MediaItem::factory()->movie()->create(['runtime' => 120]);

    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-04-01 20:00')));
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-04-01 22:00')));

    // Nothing records how much of an abandoned movie was actually watched,
    // so its runtime never counts (progress types log a real amount and do).
    Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $abandoned->id,
        'watched_at' => CarbonImmutable::parse('2026-04-02 20:00'),
        'outcome' => PlayOutcome::Dropped,
    ]);
    projectLibrary($user);

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('dayTotals.2026-04-01', ['watch' => 180])
            ->missing('dayTotals.2026-04-02'));
});

test('plays on a show root do not double-count the runtime of their episodes', function () {
    $user = User::factory()->create();
    // TMDB puts the per-episode runtime on the show itself; the cascade play
    // on the root must not add it on top of the episodes.
    $show = MediaItem::factory()->show()->create(['runtime' => 45]);
    $season = MediaItem::factory()->season($show, 1)->create();
    $episode = MediaItem::factory()->episode($season, 1)->create(['runtime' => 45]);

    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-05-01 20:00')));
    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-05-01 20:00')));

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username]))
        ->assertInertia(fn (Assert $page) => $page->where('dayTotals.2026-05-01', ['watch' => 45]));
});

test('an episode without a runtime of its own costs what its show declares, on the day and on the dashboard alike', function () {
    $user = User::factory()->create();
    // TMDB leaves whole runs of daily shows without episode runtimes.
    $show = MediaItem::factory()->show()->create(['runtime' => 42]);
    $season = MediaItem::factory()->season($show, 1)->create();
    $episode = MediaItem::factory()->episode($season, 1)->create(['runtime' => null]);

    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-05-01 20:00')));

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username]))
        ->assertInertia(fn (Assert $page) => $page->where('dayTotals.2026-05-01', ['watch' => 42]));

    expect(app(BuildWatchStats::class)->execute($user)['show']->minutes)->toBe(42);
});

test('a dropped viewing counts the minutes it logged, and nothing without them', function () {
    $user = User::factory()->create();
    $logged = MediaItem::factory()->movie()->create(['runtime' => 120]);
    $unlogged = MediaItem::factory()->movie()->create(['runtime' => 120]);

    foreach ([[$logged, 35], [$unlogged, null]] as [$movie, $amount]) {
        Play::factory()->create([
            'user_id' => $user->id,
            'media_item_id' => $movie->id,
            'watched_at' => CarbonImmutable::parse('2026-04-02 20:00'),
            'outcome' => PlayOutcome::Dropped,
            'amount' => $amount,
        ]);
    }
    projectLibrary($user);

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username]))
        ->assertInertia(fn (Assert $page) => $page->where('dayTotals.2026-04-02', ['watch' => 35]));

    expect(app(BuildWatchStats::class)->execute($user)['movie']->minutes)->toBe(35);
});

test('the history\'s date filter takes the viewer\'s days, as the page groups them', function () {
    $user = User::factory()->create(['preferences' => ['timezone' => 'Asia/Tokyo']]);
    $movie = MediaItem::factory()->movie()->create();

    // 16:30 UTC on 30 April is 01:30 on 1 May in Tokyo.
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-04-30 16:30', 'UTC')));

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'from' => '2026-05-01', 'to' => '2026-05-01']))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('entries.0.day', '2026-05-01'));

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'from' => '2026-04-30', 'to' => '2026-04-30']))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0));
});

test('the history\'s days follow the viewer\'s clock across both changes of time', function (string $instanceZone) {
    // Plays are stamped as the instance's clock reads them; the day a play
    // falls on is the viewer's, an hour apart on either side of each change.
    instanceTimezone($instanceZone);

    $user = User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw']]);
    $movie = MediaItem::factory()->movie()->create(['runtime' => 60]);

    foreach ([
        '2026-03-28 22:59', '2026-03-28 23:00', // the last minute of winter time, then midnight
        '2026-03-29 21:59', '2026-03-29 22:00', // the first day of summer time, at its two ends
        '2026-10-24 21:59', '2026-10-24 22:00', // the last minute of summer time, then midnight
        '2026-10-25 22:59', '2026-10-25 23:00', // the day back on winter time, at its two ends
    ] as $moment) {
        app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse($moment, 'UTC')->setTimezone($instanceZone)));
    }

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries', fn ($entries) => collect($entries)->pluck('day')->all() === [
                '2026-10-26', '2026-10-25', '2026-10-25', '2026-10-24',
                '2026-03-30', '2026-03-29', '2026-03-29', '2026-03-28',
            ])
            ->where('dayTotals', [
                '2026-10-26' => ['watch' => 60],
                '2026-10-25' => ['watch' => 120],
                '2026-10-24' => ['watch' => 60],
                '2026-03-30' => ['watch' => 60],
                '2026-03-29' => ['watch' => 120],
                '2026-03-28' => ['watch' => 60],
            ]));

    // Rolled up, a show sits under the viewer's day of its latest play.
    $show = makeShowTree([1 => ['2020-01-01']]);
    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-10-25 23:00', 'UTC')->setTimezone($instanceZone)));

    $this->actingAs($user)
        ->get(route('users.history', ['user' => $user->username, 'type' => 'show']))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('entries.0.day', '2026-10-26'));
})->with(['an instance on UTC' => 'UTC', 'an instance on the viewer\'s zone' => 'Europe/Warsaw']);

test('the ratings panel carries no day totals', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['runtime' => 90]);
    $user->ratings()->create(['media_item_id' => $movie->id, 'rating' => 8]);

    $this->actingAs($user)
        ->get(route('users.ratings', ['user' => $user->username]))
        ->assertInertia(fn (Assert $page) => $page->missing('dayTotals'));
});

test('an unknown panel URL is a 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get("/user/{$user->username}/nope")
        ->assertNotFound();
});

test('activity of a private profile is hidden from other users', function () {
    $owner = User::factory()->create(['is_private' => true]);

    $this->actingAs(User::factory()->create())
        ->get(route('users.history', ['user' => $owner->username]))
        ->assertNotFound();
});

test('a user can remove a single play from their history', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-02-01 21:30')));

    $play = Play::query()->where('user_id', $user->id)->orderBy('watched_at')->first();

    $this->actingAs($user)
        ->deleteJson(route('ui.plays.destroy', ['play' => $play->id]))
        ->assertSuccessful();

    expect(Play::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('removing somebody else\'s play answers as if it did not exist', function () {
    $owner = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    app(MarkWatched::class)->execute($owner, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));

    $play = Play::query()->where('user_id', $owner->id)->first();

    $this->actingAs(User::factory()->create())
        ->deleteJson(route('ui.plays.destroy', ['play' => $play->id]))
        ->assertNotFound();

    expect(Play::query()->count())->toBe(1);
});
