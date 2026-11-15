<?php

use App\Catalog\Contracts\SupportsEpisodeRatings;
use App\Catalog\ProviderRegistry;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, mixed>
 */
function tvWithEpisodeRatings(): array
{
    return [
        ...jsonFixture('tmdb/tv'),
        'season/1' => ['episodes' => [
            ['episode_number' => 1, 'name' => 'Winter Is Coming', 'vote_average' => 8.9, 'air_date' => '2011-04-17'],
            ['episode_number' => 2, 'name' => 'The Kingsroad', 'vote_average' => 8.6, 'air_date' => '2011-04-24'],
        ]],
        'season/2' => ['episodes' => [
            ['episode_number' => 1, 'name' => 'The North Remembers', 'vote_average' => 8.5, 'air_date' => '2012-04-01'],
            // Unaired/unrated episodes come through with a null rating.
            ['episode_number' => 2, 'name' => 'Upcoming', 'vote_average' => 0, 'air_date' => null],
        ]],
    ];
}

test('the show page defers episode ratings and serves them on the follow-up request', function () {
    Http::fake(['*/tv/1399*' => Http::response(tvWithEpisodeRatings()), ...providerDictionaryFakes()]);

    $user = User::factory()->create();

    // Initial load: the prop is deferred, not present.
    $this->actingAs($user)
        ->get(route('show.details', ['ref' => '1399']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/ShowDetails')
            ->missing('episodeRatings'));

    // The deferred follow-up (an Inertia partial reload) carries the data.
    $this->actingAs($user)
        ->get(route('show.details', ['ref' => '1399']), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'media/ShowDetails',
            'X-Inertia-Partial-Data' => 'episodeRatings',
        ])
        ->assertOk()
        ->assertJsonPath('props.episodeRatings.0.season', 1)
        ->assertJsonPath('props.episodeRatings.0.episodes.0.title', 'Winter Is Coming')
        ->assertJsonPath('props.episodeRatings.0.episodes.0.rating', 8.9)
        ->assertJsonPath('props.episodeRatings.1.season', 2)
        ->assertJsonPath('props.episodeRatings.1.episodes.1.rating', null);
});

test('episode ratings skip specials and batch season appends in chunks of 20', function () {
    $seasons = [];
    $response = ['id' => 99, 'name' => 'Long Show', 'poster_path' => '/p.jpg', 'overview' => 'x', 'first_air_date' => '2000-01-01'];

    foreach (range(0, 25) as $number) {
        $seasons[] = ['season_number' => $number, 'name' => "Season {$number}", 'episode_count' => 1, 'air_date' => '2000-01-01'];
        $response["season/{$number}"] = ['episodes' => [
            ['episode_number' => 1, 'name' => "S{$number}E1", 'vote_average' => 7.5, 'air_date' => '2000-01-01'],
        ]];
    }

    $response['seasons'] = $seasons;

    Http::fake(['*/tv/99*' => Http::response($response)]);

    $provider = app(ProviderRegistry::class)->get('tmdb');
    assert($provider instanceof SupportsEpisodeRatings);

    $ratings = $provider->episodeRatings('99', 'en');

    // Specials (season 0) are excluded; seasons 1..25 all arrive.
    expect(array_column($ratings, 'season'))->toBe(range(1, 25));

    // 25 seasons = two append batches: season/1..20 and season/21..25.
    Http::assertSent(function ($request): bool {
        $append = (string) ($request->data()['append_to_response'] ?? '');

        return str_starts_with($append, 'season/1,') && substr_count($append, 'season/') === 20;
    });
    Http::assertSent(function ($request): bool {
        $append = (string) ($request->data()['append_to_response'] ?? '');

        return str_starts_with($append, 'season/21,') && substr_count($append, 'season/') === 5;
    });
});

test('a provider outage yields empty episode ratings instead of an error', function () {
    Http::fake(['*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')), ...providerDictionaryFakes()]);

    $user = User::factory()->create();

    // Prime the show details cache with the page visit…
    $this->actingAs($user)->get(route('show.details', ['ref' => '1399']))->assertOk();

    // …then the season fetches of the deferred request hit an outage.
    Http::fake(['*/tv/1399*' => Http::response([], 500)]);

    $this->actingAs($user)
        ->get(route('show.details', ['ref' => '1399']), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'media/ShowDetails',
            'X-Inertia-Partial-Data' => 'episodeRatings',
        ])
        ->assertOk()
        ->assertJsonPath('props.episodeRatings', []);
});
