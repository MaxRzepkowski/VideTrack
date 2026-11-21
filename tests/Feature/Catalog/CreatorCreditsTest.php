<?php

use App\Catalog\MatureContent;
use App\Models\MediaItem;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * A studio's filmography runs to thousands of titles and discover pays out
 * twenty at a time, so company and network pages stream: the render carries the
 * first chunk and the page asks this endpoint for the ones behind it.
 */

/** A discover fake whose catalogue is `$totalPages` pages of one title each. */
function fakeDiscover(int $totalPages): Closure
{
    return function ($request) use ($totalPages) {
        preg_match('/[?&]page=(\d+)/', $request->url(), $matches);

        $page = (int) ($matches[1] ?? 1);
        $movie = str_contains($request->url(), 'discover/movie');

        // The upcoming slice (a date floor) is not what is being counted here.
        if (str_contains($request->url(), 'gte')) {
            return Http::response(['page' => 1, 'total_pages' => 1, 'results' => []]);
        }

        return Http::response([
            'page' => $page,
            'total_pages' => $totalPages,
            'results' => [[
                'id' => ($movie ? 1000 : 2000) + $page,
                $movie ? 'title' : 'name' => 'Title '.$page,
                $movie ? 'release_date' : 'first_air_date' => '2020-01-01',
                'poster_path' => '/p.jpg',
            ]],
        ]);
    };
}

test('a studio filmography streams chunk after chunk until the provider runs out', function () {
    Http::fake([
        '*/company/41077*' => Http::response(['id' => 41077, 'name' => 'A24']),
        '*/discover/movie*' => fakeDiscover(7),
        '*/discover/tv*' => fakeDiscover(7),
    ]);

    // Five discover pages per endpoint, so the first chunk is pages 1–5 of both.
    $first = tmdbProvider()->companyCredits('41077', 'en', MatureContent::Suggestive, 1);

    expect($first->credits)->toHaveCount(10)
        ->and($first->hasMore)->toBeTrue();

    // The second chunk picks up at page 6 and the catalogue ends at 7.
    $second = tmdbProvider()->companyCredits('41077', 'en', MatureContent::Suggestive, 2);

    expect($second->credits)->toHaveCount(4)
        ->and($second->hasMore)->toBeFalse();

    // The page render carries the first chunk and says more is waiting.
    $company = tmdbProvider()->company('41077', 'en', MatureContent::Suggestive);

    expect($company->credits)->toHaveCount(10)
        ->and($company->hasMoreCredits)->toBeTrue();
});

test('a network showography reports the end of the catalogue', function () {
    Http::fake([
        '*/network/49*' => Http::response(['id' => 49, 'name' => 'HBO']),
        '*/discover/tv*' => fakeDiscover(3),
    ]);

    $network = tmdbProvider()->network('49', 'en', MatureContent::Suggestive);

    expect($network->credits)->toHaveCount(3)
        ->and($network->hasMoreCredits)->toBeFalse();
});

test('the credits endpoint serves the next chunk with the states its cards need', function () {
    Http::fake([
        '*/company/41077*' => Http::response(['id' => 41077, 'name' => 'A24']),
        '*/discover/movie*' => fakeDiscover(7),
        '*/discover/tv*' => fakeDiscover(7),
    ]);

    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['provider_ref' => '1006']);

    Rating::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'rating' => 9]);

    $this->actingAs($user)
        ->getJson(route('company.credits', ['provider' => 'tmdb', 'ref' => '41077', 'page' => 2]))
        ->assertOk()
        ->assertJsonPath('hasMore', false)
        ->assertJsonPath('credits.0.media.ref', '1006')
        ->assertJsonPath('userStates.movie:1006.rating', 9);
});

test('the credits endpoint never re-serves the chunk the page already has', function () {
    Http::fake([
        '*/company/41077*' => Http::response(['id' => 41077, 'name' => 'A24']),
        '*/discover/movie*' => fakeDiscover(7),
        '*/discover/tv*' => fakeDiscover(7),
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('company.credits', ['provider' => 'tmdb', 'ref' => '41077', 'page' => 1]))
        ->assertOk()
        ->assertJsonPath('credits.0.media.ref', '1006');
});

test('the network credits endpoint answers for networks only', function () {
    // Twelve pages: the second chunk (6–10) still leaves some behind.
    Http::fake(['*/discover/tv*' => fakeDiscover(12)]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('network.credits', ['provider' => 'tmdb', 'ref' => '49', 'page' => 2]))
        ->assertOk()
        ->assertJsonPath('hasMore', true)
        ->assertJsonPath('credits.0.media.type', 'show');

    // A provider without the capability is a 404, like the page itself.
    $this->actingAs(User::factory()->create())
        ->getJson('/network/nope/49/credits')
        ->assertNotFound();
});
