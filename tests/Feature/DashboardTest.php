<?php

use App\Actions\Library\MarkWatched;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

test('guests are redirected to the login page', function () {
    $response = $this->get('/dashboard');
    $response->assertRedirect('/login');
});

test('a new account receives zeroed stats for every enabled root type', function () {
    enableGamesModule();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.movie', ['amount' => 0, 'watched' => 0, 'plays' => 0, 'titles' => 0, 'estimatedMinutes' => null])
            ->where('stats.show', ['amount' => 0, 'watched' => 0, 'plays' => 0, 'titles' => 0, 'estimatedMinutes' => null])
            ->where('stats.game', ['amount' => 0, 'watched' => 0, 'plays' => 0, 'titles' => 0, 'estimatedMinutes' => null]));
});

// No provider is called: a stray request fails the test (tests/Pest.php).
test('the dashboard serves up next calendar and stats', function () {
    $user = User::factory()->create();

    $show = MediaItem::factory()->show()->create();
    $season = MediaItem::factory()->season($show, 1)->create();
    $first = MediaItem::factory()->episode($season, 1, '2020-01-01')->create();
    $gap = MediaItem::factory()->episode($season, 2, '2020-01-08')->create();
    MediaItem::factory()->episode($season, 3, today()->addDays(40)->toDateString())->create();

    app(MarkWatched::class)->execute($user, $first, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('upNext.0.episode.ref', $gap->provider_ref)
            ->where('upNext.0.watchedEpisodes', 1)
            ->where('upNext.0.airedEpisodes', 2)
            ->has('calendar', 1)
            ->where('calendar.0.date', today()->addDays(40)->toDateString())
            ->where('stats.show.plays', 1)
            ->has('userStates'),
        );
});

test('an up next card names its badge and its progress segments as the front reads them', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01'], 2 => ['2020-02-01', '2020-02-08']]);

    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('upNext.0.episode.ref', episodeOf($show, 2, 1)->provider_ref)
            ->where('upNext.0.badge', 'season_premiere')
            ->where('upNext.0.closes', null)
            ->where('upNext.0.segments', [['state' => 'watched', 'episodes' => 1], ['state' => 'pending', 'episodes' => 2]]),
        );
});
