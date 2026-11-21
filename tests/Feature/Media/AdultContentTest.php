<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/** A viewer who lets films and shows go as far as `$level`. */
function viewerAllowingFilms(string $level): User
{
    return User::factory()->create(['preferences' => ['mature_content' => ['tmdb' => $level]]]);
}

/** Whether every request to the TMDB path asked for adult titles as `$adult` says. */
function tmdbAskedForAdult(string $path, bool $adult): bool
{
    $asked = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), $path))
        ->map(fn (array $pair): mixed => $pair[0]->data()['include_adult'] ?? null);

    return $asked->isNotEmpty() && $asked->every(fn (mixed $value): bool => $value === $adult);
}

test('search keeps to the level the viewer chose for the provider', function (?string $level, bool $adult) {
    Http::fake(['*/search/multi*' => Http::response(jsonFixture('tmdb/search'))]);
    $viewer = $level === null ? User::factory()->create() : viewerAllowingFilms($level);

    $this->actingAs($viewer)->get(route('search', ['q' => 'matrix']))->assertOk();

    expect(tmdbAskedForAdult('search/multi', $adult))->toBeTrue();
})->with([
    'no choice' => [null, false],
    'adult titles hidden' => ['suggestive', false],
    'everything' => ['adult', true],
]);

test('the API search keeps to the same level as the page', function () {
    Http::fake(['*/search/multi*' => Http::response(jsonFixture('tmdb/search'))]);

    $this->actingAs(viewerAllowingFilms('adult'))->getJson(route('ui.search', ['q' => 'matrix', 'scope' => 'tmdb']))->assertOk();

    expect(tmdbAskedForAdult('search/multi', true))->toBeTrue();
});

test('a person page leaves adult films out unless the viewer lets them in', function (string $level, bool $shown) {
    $person = jsonFixture('tmdb/person');
    $person['combined_credits']['cast'][] = ['id' => 777001, 'media_type' => 'movie', 'title' => 'Adult Feature', 'adult' => true, 'release_date' => '2010-01-01', 'poster_path' => '/a.jpg', 'popularity' => 1.0];
    Http::fake(['*/person/6384*' => Http::response($person)]);

    $this->actingAs(viewerAllowingFilms($level))
        ->get(route('person.details', ['provider' => 'tmdb', 'ref' => '6384']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('creator.credits', fn ($credits) => collect($credits)->contains(fn ($credit) => $credit['media']['ref'] === '777001') === $shown));
})->with([
    'adult titles hidden' => ['suggestive', false],
    'everything' => ['adult', true],
]);

test("a network's later shows keep to the viewer's level", function () {
    Http::fake(['*/discover/tv*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'results' => []])]);

    $this->actingAs(viewerAllowingFilms('adult'))
        ->getJson(route('network.credits', ['provider' => 'tmdb', 'ref' => '49', 'page' => 2]))
        ->assertOk();

    expect(tmdbAskedForAdult('discover/tv', true))->toBeTrue();
});

test('the people search keeps to the level the viewer chose for the provider', function () {
    Http::fake(['*/search/person*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'results' => []])]);

    $this->actingAs(viewerAllowingFilms('adult'))->get(route('search', ['q' => 'keanu', 'scope' => 'person']))->assertOk();

    expect(tmdbAskedForAdult('search/person', true))->toBeTrue();
});

test("Discover's coming-up row keeps to the viewer's level, its trending row to everyone's", function () {
    Http::fake([
        '*/trending/*' => Http::response(['results' => []]),
        '*/movie/popular*' => Http::response(['results' => []]),
        '*/genre/movie/list*' => Http::response(['genres' => []]),
        '*/discover/movie*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'results' => []]),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(viewerAllowingFilms('adult'))->get(route('discover', ['type' => 'movie']))->assertOk();

    expect(tmdbAskedForAdult('discover/movie', true))->toBeTrue();
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'trending/') && array_key_exists('include_adult', $request->data()));
});

test("a studio page's first shelf keeps to the viewer's level", function () {
    Http::fake([
        '*/company/41077*' => Http::response(['id' => 41077, 'name' => 'A24', 'logo_path' => null, 'origin_country' => 'US']),
        '*/discover/*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'results' => []]),
    ]);

    $this->actingAs(viewerAllowingFilms('adult'))->get(route('company.details', ['provider' => 'tmdb', 'ref' => '41077']))->assertOk();

    expect(tmdbAskedForAdult('discover/movie', true))->toBeTrue()
        ->and(tmdbAskedForAdult('discover/tv', true))->toBeTrue();
});
