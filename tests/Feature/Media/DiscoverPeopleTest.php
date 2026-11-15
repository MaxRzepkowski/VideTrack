<?php

use App\Catalog\ProviderRegistry;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FilteredOnlyProvider;

/**
 * People in Discover: `/discover?type=person`. The subject is the one value of the type
 * select that is not a media type — it must reach the select and the mega-menu
 * from the same gate as the rest, page like the filtered grid, and carry list
 * membership instead of watched state.
 */

/** @param  array<int, array<string, mixed>>  $results */
function fakePopularPeople(array $results, int $page = 1, int $totalPages = 1, int $total = 1): void
{
    Http::fake([
        '*/person/popular*' => Http::response([
            'page' => $page,
            'total_pages' => $totalPages,
            'total_results' => $total,
            'results' => $results,
        ]),
    ]);
}

test('the people mode maps popular people and carries list membership, not media state', function () {
    fakePopularPeople([
        [
            'id' => 6384,
            'name' => 'Keanu Reeves',
            'profile_path' => '/keanu.jpg',
            'known_for_department' => 'Acting',
            'known_for' => [
                ['title' => 'The Matrix'],
                ['name' => 'John Wick'],
                ['title' => ''],
            ],
        ],
        // No portrait → nothing to show on a card.
        ['id' => 99, 'name' => 'Faceless', 'profile_path' => null],
    ], total: 2);

    $user = User::factory()->create();
    $list = $user->lists()->create(['title' => 'Favourite actors']);
    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);
    $list->items()->create(['person_id' => $person->id, 'position' => 1]);

    $this->actingAs($user)
        ->get(route('discover', ['type' => 'person']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/Discover')
            ->where('type', 'person')
            // Same gate as every other value of the select, shared with the
            // header's mega-menu.
            ->where('availableTypes', fn (Collection $types): bool => $types->contains('person'))
            ->where('discoverTypes', fn (Collection $types): bool => $types->contains('person'))
            ->has('people', 1)
            ->where('people.0.type', 'person')
            ->where('people.0.ref', '6384')
            ->where('people.0.title', 'Keanu Reeves')
            ->where('people.0.posterPath', '/keanu.jpg')
            ->where('people.0.extra.knownFor', ['The Matrix', 'John Wick'])
            ->where('people.0.extra.knownForDepartment', 'acting')
            ->where('peoplePagination.currentPage', 1)
            ->where('peoplePagination.total', 2)
            // People have no watched or rating state; the card knows only its
            // list membership and its bell.
            ->where('userStates', [])
            ->where('creatorStates', ['person:tmdb:6384' => ['followed' => false, 'listIds' => [$list->id]]])
            // None of the title page's surfaces come along.
            ->where('filters', null)
            ->where('filterCount', null)
            ->where('genres', [])
            ->where('trending', [])
            ->where('popular', [])
            ->where('upcoming', [])
            ->where('failed', false));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/person/popular')
        && $request['page'] === 1
        && $request['language'] === 'en-US');
});

test('a page past the last one is rewritten rather than shown empty', function () {
    fakePopularPeople([], page: 3, totalPages: 3, total: 55);

    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'person', 'page' => 9]))
        ->assertRedirect(route('discover', ['type' => 'person', 'page' => 3]));
});

test('a page past TMDB own ceiling is capped before the request leaves', function () {
    fakePopularPeople([], page: 500, totalPages: 500, total: 10000);

    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'person', 'page' => 501]))
        ->assertRedirect(route('discover', ['type' => 'person', 'page' => 500]));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/person/popular') && $request['page'] === 500);
});

test('an unreachable provider degrades the people grid instead of the page', function () {
    Http::fake(['*/person/popular*' => Http::response([], 503)]);

    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'person']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('people', [])
            ->where('peoplePagination.totalPages', 1)
            ->where('failed', true));
});

test('without a people-capable provider the value is neither offered nor reachable', function () {
    app(ProviderRegistry::class)->register('tmdb', FilteredOnlyProvider::class);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('discover', ['type' => 'show']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('availableTypes', fn (Collection $types): bool => ! $types->contains('person'))
            ->where('discoverTypes', fn (Collection $types): bool => ! $types->contains('person')));

    $this->actingAs($user)->get(route('discover', ['type' => 'person']))->assertNotFound();
});

test('the browse sections stay untouched when the select goes back to titles', function () {
    Http::fake([
        '*/person/popular*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'results' => []]),
        '*/trending/*' => Http::response(['results' => []]),
        '*/tv/popular*' => Http::response(['results' => []]),
        '*/tv/on_the_air*' => Http::response(['results' => []]),
        '*/genre/tv/list*' => Http::response(['genres' => [['id' => 18, 'name' => 'Drama']]]),
        '*/configuration/languages*' => Http::response([['iso_639_1' => 'en', 'english_name' => 'English']]),
        '*/configuration/countries*' => Http::response([['iso_3166_1' => 'US', 'english_name' => 'United States']]),
        '*/certification/tv/list*' => Http::response(jsonFixture('tmdb/certifications_tv')),
        '*/watch/providers/*' => Http::response(['results' => []]),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'show']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('type', 'show')
            ->where('people', [])
            ->where('peoplePagination', null)
            ->has('filters'));

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/person/popular'));
});
