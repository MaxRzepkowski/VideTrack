<?php

use App\Actions\Media\ResolveTotalRuntime;
use App\Catalog\ProviderRegistry;
use App\Catalog\TitleStore;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

// Season runtimes in the fixtures: specials 30 + 15, season 1 62 + 56,
// season 2 53 + 54 — 225 minutes for the whole show, the 45 minutes of
// specials excluded.
function fakeTotalRuntimeTree(): void
{
    Http::fake([
        '*/tv/1399/season/0*' => Http::response(jsonFixture('tmdb/season0')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399/season/2*' => Http::response(jsonFixture('tmdb/season2')),
        '*/tv/1399*' => tmdbShowAnswer(),
    ]);
}

/** The show fixture plus the appended season payloads of a batched request. */
function fakeBatchedSeasons(): void
{
    $response = jsonFixture('tmdb/tv');

    foreach ([0 => 'season0', 1 => 'season', 2 => 'season2'] as $number => $fixture) {
        $response["season/{$number}"] = ['episodes' => jsonFixture("tmdb/{$fixture}")['episodes']];
    }

    Http::fake(['*/tv/1399*' => Http::response($response)]);
}

/** The deferred follow-up request that carries the show's total. */
function fetchShowTotalRuntime(User $user): TestResponse
{
    return test()->actingAs($user)->get(route('show.details', ['ref' => '1399']), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia::getVersion(),
        'X-Inertia-Partial-Component' => 'media/ShowDetails',
        'X-Inertia-Partial-Data' => 'totalRuntime',
    ]);
}

test('the season page sums the runtimes of its episodes', function () {
    Http::fake(providerDictionaryFakes());

    fakeTotalRuntimeTree();

    $this->actingAs(User::factory()->create())
        ->get(route('season.details', ['ref' => '1399', 'number' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/SeasonDetails')
            ->where('totalRuntime', 118));
});

test('an untracked show totals every episode of every season but the specials', function () {
    Http::fake(providerDictionaryFakes());

    fakeBatchedSeasons();

    $user = User::factory()->create();

    // Deferred: the chip must not hold the page while the episodes are fetched.
    $this->actingAs($user)
        ->get(route('show.details', ['ref' => '1399']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->missing('totalRuntime'));

    fetchShowTotalRuntime($user)->assertOk()->assertJsonPath('props.totalRuntime', 225);
});

test('a specials season asked directly still totals its own episodes', function () {
    Http::fake(providerDictionaryFakes());

    fakeTotalRuntimeTree();

    $this->actingAs(User::factory()->create())
        ->get(route('season.details', ['ref' => '1399', 'number' => 0]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/SeasonDetails')
            ->where('totalRuntime', 45));
});

test('an untracked show costs one batched request per 20 seasons, not one per season', function () {
    $response = ['id' => 99, 'name' => 'Long Show', 'poster_path' => '/p.jpg', 'overview' => 'x', 'first_air_date' => '2000-01-01'];
    $seasons = [];

    foreach (range(1, 26) as $number) {
        $seasons[] = ['season_number' => $number, 'name' => "Season {$number}", 'episode_count' => 1, 'air_date' => '2000-01-01'];
        $response["season/{$number}"] = ['episodes' => [
            ['episode_number' => 1, 'name' => "S{$number}E1", 'runtime' => 30, 'air_date' => '2000-01-01'],
        ]];
    }

    $response['seasons'] = $seasons;

    Http::fake(['*/tv/99*' => Http::response($response)]);

    $details = app(ProviderRegistry::class)->get('tmdb')->details('show', '99', 'en');

    expect(app(ResolveTotalRuntime::class)->execute('show', '99', $details, 'en'))->toBe(26 * 30);

    // The show details request plus two append batches (20 + 6 seasons).
    Http::assertSentCount(3);
});

test('a tracked show reads its total from the stored tree', function () {
    Http::fake(providerDictionaryFakes());
    fakeTotalRuntimeTree();

    app(TitleStore::class)->persist('show', '1399', 'en');

    // Only the plain show payload is on offer now: without the stored roll-up
    // the total could not be assembled at all.
    Http::fake(['*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')), ...providerDictionaryFakes()]);

    $user = User::factory()->create();

    $this->actingAs($user)->get(route('show.details', ['ref' => '1399']))->assertOk();

    fetchShowTotalRuntime($user)->assertOk()->assertJsonPath('props.totalRuntime', 225);

    // …and no episodes were fetched for it.
    Http::assertNotSent(fn ($request): bool => str_contains((string) ($request->data()['append_to_response'] ?? ''), 'season/'));
});
