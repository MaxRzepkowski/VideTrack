<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Library\StartRewatch;
use App\Enums\ActivityGrouping;
use App\Enums\ActivitySort;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use App\Users\ActivityFeed;
use App\Users\ActivitySource;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

function finishOn(User $user, MediaItem $item, string $moment): void
{
    app(MarkWatched::class)->execute($user, $item, WatchedAt::at(CarbonImmutable::parse($moment)));
}

/**
 * The history sorted by times finished, as the page asks for it.
 *
 * @param  array<string, string>  $query
 * @return array<string, string>
 */
function byCompletions(User $user, array $query = []): array
{
    return ['user' => $user->username, 'sort' => ActivitySort::Completions->value, 'group' => ActivityGrouping::Grid->value, ...$query];
}

test('the history lists each position once, the most finished first, with its count', function () {
    $user = User::factory()->create();
    $once = MediaItem::factory()->movie()->create(['title' => 'Once']);
    $thrice = MediaItem::factory()->movie()->create(['title' => 'Thrice']);

    finishOn($user, $once, '2026-05-01 20:00');

    foreach (['2024-01-01 20:00', '2025-01-01 20:00', '2026-01-01 20:00'] as $moment) {
        finishOn($user, $thrice, $moment);
    }

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user)))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', ActivitySort::Completions->value)
            ->where('filters.byCompletions', true)
            ->has('entries', 2)
            ->where('entries.0.media.title', 'Thrice')
            ->where('entries.0.completions', 3)
            // No single play behind the card, nothing to remove from here.
            ->where('entries.0.id', null)
            ->where('entries.0.at', '2026-01-01T20:00:00+00:00')
            ->where('entries.1.media.title', 'Once')
            ->where('entries.1.completions', 1)
            ->where('dayTotals', []));

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['dir' => 'asc'])))
        ->assertInertia(fn (Assert $page) => $page->where('entries.0.media.title', 'Once'));
});

test('without a type the episodes stand for themselves; a show is listed when asked for', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);
    $episode = episodeOf($show, 1, 1);

    finishOn($user, $show, '2026-01-01 20:00');
    finishOn($user, $episode, '2026-02-01 20:00');

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user)))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 2)
            ->where('entries.0.media.ref', $episode->provider_ref)
            ->where('entries.0.completions', 2)
            ->where('entries.1.completions', 1));

    // An episode watched again on its own does not watch the show again.
    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['type' => 'show'])))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.type', 'show')
            ->where('entries.0.completions', 1));
});

test('a show half watched is not finished, though its watched episode is', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);

    finishOn($user, episodeOf($show, 1, 1), '2026-01-01 20:00');

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['type' => 'show'])))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0));

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user)))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 1));
});

test('a film only ever dropped is not on the list', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    Play::factory()->dropped()->for($user)->for($movie)->create(['watched_at' => '2026-01-01 20:00']);
    projectLibrary($user);

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user)))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0));
});

test('the date range picks the positions but keeps every time they were finished', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    foreach (['2024-03-01 20:00', '2025-03-01 20:00', '2026-03-01 20:00'] as $moment) {
        finishOn($user, $movie, $moment);
    }

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['from' => '2025-01-01', 'to' => '2025-12-31'])))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.completions', 3));

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['from' => '2023-01-01', 'to' => '2023-12-31'])))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0));

    // A range open on one side.
    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['from' => '2026-06-01'])))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0));
});

test('the range follows the viewer’s days', function () {
    $user = User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw']]);
    $movie = MediaItem::factory()->movie()->create();

    // 23:30 UTC on the 31st is already the 1st in Warsaw.
    finishOn($user, $movie, '2025-12-31 23:30');

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['from' => '2026-01-01', 'to' => '2026-01-01'])))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 1));

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['from' => '2025-12-31', 'to' => '2025-12-31'])))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0));
});

test('a show counts its full runs, and an episode finished in the range brings it in', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);

    finishOn($user, $show, '2026-01-01 20:00');
    app(StartRewatch::class)->execute($user, $show);
    finishOn($user, episodeOf($show, 1, 1), '2026-03-01 20:00');
    finishOn($user, episodeOf($show, 1, 2), '2026-03-02 20:00');

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['type' => 'show', 'from' => '2026-03-02', 'to' => '2026-03-02'])))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.type', 'show')
            ->where('entries.0.completions', 2));

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['type' => 'season', 'from' => '2026-03-01', 'to' => '2026-03-01'])))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.type', 'season')
            ->where('entries.0.completions', 2));
});

test('a show watched again is dated by its last watched episode, before the new run has any', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);

    finishOn($user, $show, '2026-01-01 20:00');
    app(StartRewatch::class)->execute($user, $show);

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['type' => 'show'])))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.completions', 1)
            ->where('entries.0.at', '2026-01-01T20:00:00+00:00'));

    finishOn($user, episodeOf($show, 1, 1), '2026-03-01 20:00');

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['type' => 'show'])))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.completions', 1)
            ->where('entries.0.at', '2026-03-01T20:00:00+00:00'));
});

test('a show is dated by the episode last finished, not by one dropped since', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);

    finishOn($user, $show, '2026-01-01 20:00');
    Play::factory()->dropped()->for($user)->for(episodeOf($show, 1, 1))->create(['watched_at' => '2026-04-01 20:00']);
    projectLibrary($user);

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['type' => 'show'])))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.completions', 1)
            ->where('entries.0.at', '2026-01-01T20:00:00+00:00'));
});

test('a show with a season that aired after it was finished is no longer finished', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01']]);

    finishOn($user, $show, '2026-01-01 20:00');

    $season = MediaItem::factory()->season($show, 2)->create();
    MediaItem::factory()->episode($season, 1, '2026-06-01')->create();
    refreshTitle($show);

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['type' => 'show'])))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0));
});

test('the title filters judge the title, and the pages count positions', function () {
    $user = User::factory()->create();

    foreach (range(1, ActivityFeed::PER_PAGE + 1) as $index) {
        finishOn($user, MediaItem::factory()->movie()->create(['title' => "Film {$index}"]), '2026-01-01 20:00');
    }

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user)))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', ActivityFeed::PER_PAGE)
            ->where('pagination.total', ActivityFeed::PER_PAGE + 1)
            ->where('pagination.totalPages', 2));

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['q' => 'Film 7'])))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.title', 'Film 7'));
});

test('grouped by day the history stays a timeline of plays', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    finishOn($user, $movie, '2026-01-01 20:00');
    finishOn($user, $movie, '2026-02-01 20:00');

    $this->actingAs($user)
        ->get(route('users.history', byCompletions($user, ['group' => ActivityGrouping::Day->value])))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.byCompletions', false)
            ->has('entries', 2)
            ->whereNot('entries.0.id', null)
            ->where('entries.0.completions', null));
});

test('a panel with nothing to finish falls back to its own sort', function () {
    $user = User::factory()->create();
    $asked = ['user' => $user->username, 'sort' => ActivitySort::Completions->value];

    $this->actingAs($user)
        ->get(route('users.ratings', $asked))
        ->assertInertia(fn (Assert $page) => $page->where('filters.sort', ActivitySource::Ratings->defaultSort()->value));

    $this->actingAs($user)
        ->get(route('users.favorites', $asked))
        ->assertInertia(fn (Assert $page) => $page->where('filters.sort', ActivitySource::Favorites->defaultSort()->value));
});
