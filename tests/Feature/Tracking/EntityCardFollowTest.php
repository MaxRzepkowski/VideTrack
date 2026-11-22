<?php

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorRef;
use App\Enums\FollowMode;
use App\Library\CreatorStateQuery;
use App\Models\Company;
use App\Models\Follow;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The bell on a person / company card: the `creatorStates` prop that lights
 * it, on every page those cards appear on.
 * The write itself is the person / company follow endpoint — what is tested
 * here is that a card can reach it and see the result.
 */
test('the people grid says which of its people the bell is lit for', function () {
    Http::fake([
        '*/person/popular*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 2, 'results' => [
            ['id' => 6384, 'name' => 'Keanu Reeves', 'profile_path' => '/keanu.jpg'],
            ['id' => 287, 'name' => 'Brad Pitt', 'profile_path' => '/brad.jpg'],
        ]]),
    ]);

    $user = User::factory()->create();
    $followed = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);
    // Persisted but not followed — a row alone must not light the bell.
    Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '287', 'name' => 'Brad Pitt']);

    Follow::factory()->create(['user_id' => $user->id, 'person_id' => $followed->id, 'mode' => FollowMode::Follow]);

    $this->actingAs($user)
        ->get(route('discover', ['type' => 'person']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('creatorStates.person:tmdb:6384.followed', true));
});

test('following from a card lights it on the very next render', function () {
    Http::fake([
        '*/person/popular*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 1, 'results' => [
            ['id' => 6384, 'name' => 'Keanu Reeves', 'profile_path' => '/keanu.jpg'],
        ]]),
        '*/person/6384*' => Http::response(jsonFixture('tmdb/person')),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow'])
        ->assertSuccessful();

    $this->actingAs($user)
        ->get(route('discover', ['type' => 'person']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('creatorStates.person:tmdb:6384.followed', true));

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => null])
        ->assertSuccessful();

    $this->actingAs($user)
        ->get(route('discover', ['type' => 'person']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('creatorStates.person:tmdb:6384.followed', false));
});

test('the company scope of search carries the bell state, keyed by kind', function () {
    Http::fake([
        '*/search/company*' => Http::response(['results' => [
            ['id' => 41077, 'name' => 'A24', 'logo_path' => '/a24.png'],
        ]]),
    ]);

    $user = User::factory()->create();
    $company = Company::factory()->create(['provider' => 'tmdb', 'kind' => 'company', 'provider_ref' => '41077', 'name' => 'A24']);
    // The same numeric ref as a network: a studio's bell must not light it.
    Company::factory()->create(['provider' => 'tmdb', 'kind' => 'network', 'provider_ref' => '41077', 'name' => 'A24 TV']);

    Follow::factory()->create(['user_id' => $user->id, 'company_id' => $company->id, 'mode' => FollowMode::Follow]);

    $this->actingAs($user)
        ->get(route('search', ['q' => 'a24', 'scope' => 'company']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('creatorStates', ['company:tmdb:41077' => ['followed' => true, 'listIds' => []]]));
});

test('typing a name keeps the bell lit: the live search asks for the entity props', function () {
    Http::fake([
        '*/search/person*' => Http::response(['results' => [
            ['id' => 287, 'name' => 'Brad Pitt', 'profile_path' => '/brad.jpg', 'known_for' => []],
        ]]),
    ]);

    $user = User::factory()->create();
    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '287', 'name' => 'Brad Pitt']);
    $list = $user->lists()->create(['title' => 'Favourite actors']);

    $list->items()->create(['person_id' => $person->id, 'position' => 1]);
    Follow::factory()->create(['user_id' => $user->id, 'person_id' => $person->id, 'mode' => FollowMode::Follow]);

    // A partial reload always follows a full visit.
    $this->actingAs($user)->get(route('search', ['scope' => 'person']))->assertOk();

    // The visit a keystroke makes: only the props Search.vue names come back,
    // and the card's state has to be among them.
    $this->actingAs($user)
        ->get(route('search', ['q' => 'pitt', 'scope' => 'person']), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'media/Search',
            'X-Inertia-Partial-Data' => 'query,scope,results,userStates,creatorStates',
        ])
        ->assertOk()
        ->assertJsonPath('props.creatorStates', ['person:tmdb:287' => ['followed' => true, 'listIds' => [$list->id]]]);
});

test('a muted row never lights an entity bell', function () {
    Http::fake([
        '*/person/popular*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 1, 'results' => [
            ['id' => 6384, 'name' => 'Keanu Reeves', 'profile_path' => '/keanu.jpg'],
        ]]),
    ]);

    $user = User::factory()->create();
    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);

    Follow::factory()->create(['user_id' => $user->id, 'person_id' => $person->id, 'mode' => FollowMode::Mute]);

    $this->actingAs($user)
        ->get(route('discover', ['type' => 'person']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('creatorStates.person:tmdb:6384.followed', false));
});

test('a list page carries the bell state of its person and company positions', function () {
    $user = User::factory()->create();
    $list = $user->lists()->create(['title' => 'Favourite creators']);

    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);
    $company = Company::factory()->create(['provider' => 'igdb', 'kind' => 'company', 'provider_ref' => '51', 'name' => 'Nintendo']);

    $list->items()->create(['person_id' => $person->id, 'position' => 1]);
    $list->items()->create(['company_id' => $company->id, 'position' => 2]);

    Follow::factory()->create(['user_id' => $user->id, 'person_id' => $person->id, 'mode' => FollowMode::Follow]);

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('creatorStates.person:tmdb:6384', ['followed' => true, 'listIds' => [$list->id]])
            ->where('creatorStates.company:igdb:51', ['followed' => false, 'listIds' => [$list->id]]));
});

test('the following panel lights the bells of the very rows it lists', function () {
    $user = User::factory()->create();

    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);
    $company = Company::factory()->create(['provider' => 'igdb', 'kind' => 'company', 'provider_ref' => '51', 'name' => 'Nintendo']);

    Follow::factory()->create(['user_id' => $user->id, 'person_id' => $person->id, 'mode' => FollowMode::Follow]);
    Follow::factory()->create(['user_id' => $user->id, 'company_id' => $company->id, 'mode' => FollowMode::Follow]);

    $this->actingAs($user)
        ->get(route('follows.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('creatorStates.person:tmdb:6384.followed', true)
            ->where('creatorStates.company:igdb:51.followed', true));
});

test('the bell and lists of many creators cost the same few queries as of one', function () {
    $user = User::factory()->create();
    $query = app(CreatorStateQuery::class);
    $creators = fn (int $count, int $from = 1): array => array_merge(...array_map(function (int $n) use ($user): array {
        $person = Person::factory()->create();
        $company = Company::factory()->create(['provider' => 'igdb', 'kind' => 'company', 'provider_ref' => (string) $n, 'name' => "Studio {$n}"]);
        Follow::factory()->create(['user_id' => $user->id, 'person_id' => $person->id, 'mode' => FollowMode::Follow]);

        return [
            new CreatorRef(CreatorKind::Person, $person->provider, $person->provider_ref),
            new CreatorRef(CreatorKind::Company, 'igdb', (string) $n),
        ];
    }, range($from, $from + $count - 1)));

    $few = $creators(1);
    DB::enableQueryLog();
    $query->of($user, $few);
    $forFew = count(DB::getQueryLog());

    $many = $creators(8, 100);
    DB::flushQueryLog();
    $states = $query->of($user, $many);

    expect(count(DB::getQueryLog()))->toBe($forFew)
        ->and($states)->toHaveCount(16)
        ->and(collect($states)->where('followed', true))->toHaveCount(8);
});
