<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

test('the collection scope maps TMDB collections and carries no media state', function () {
    Http::fake([
        '*/search/collection*' => Http::response(['results' => [
            [
                'id' => 10,
                'name' => 'Star Wars Collection',
                'poster_path' => '/star-wars.jpg',
                'backdrop_path' => '/star-wars-bg.jpg',
            ],
            ['id' => 11, 'name' => '', 'poster_path' => null],
        ]]),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('search', ['q' => 'star wars', 'scope' => 'collection']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/Search')
            ->where('scope', 'collection')
            ->has('results', 1)
            ->where('results.0.type', 'collection')
            ->where('results.0.ref', '10')
            ->where('results.0.title', 'Star Wars Collection')
            ->where('results.0.posterPath', '/star-wars.jpg')
            ->where('results.0.backdropPath', '/star-wars-bg.jpg')
            ->where('results.0.provider', 'tmdb')
            ->where('userStates', [])
        );

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/search/collection')
        && $request['query'] === 'star wars'
        && $request['language'] === 'en-US');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/search/company'));
});

test('the company scope maps TMDB companies and carries no media state', function () {
    Http::fake([
        '*/search/company*' => Http::response(['results' => [
            ['id' => 41077, 'name' => 'A24', 'logo_path' => '/a24.png'],
        ]]),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('search', ['q' => 'a24', 'scope' => 'company']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/Search')
            ->where('scope', 'company')
            ->has('results', 1)
            ->where('results.0.type', 'company')
            ->where('results.0.ref', '41077')
            ->where('results.0.title', 'A24')
            ->where('results.0.posterPath', '/a24.png')
            ->where('results.0.provider', 'tmdb')
            ->where('results.0.extra.kind', 'company')
            ->where('userStates', [])
        );

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/search/company')
        && $request['query'] === 'a24'
        && ! array_key_exists('language', $request->data()));
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/search/collection'));
});

test('an unavailable provider fails the search, not reports it found nothing', function () {
    Log::spy();
    Http::fake(['*/search/company*' => Http::response([], 503)]);

    $this->actingAs(User::factory()->create())
        ->get(route('search', ['q' => 'a24', 'scope' => 'company']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'company')
            ->where('results', null)
            ->where('unavailable', true)
            ->where('userStates', [])
        );

    Log::shouldHaveReceived('warning')->once();
});

test('a search that finds nothing is not a failure', function () {
    Http::fake(['*/search/company*' => Http::response(['results' => []])]);

    $this->actingAs(User::factory()->create())
        ->get(route('search', ['q' => 'zzz', 'scope' => 'company']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('results', [])
            ->where('unavailable', false)
        );
});
