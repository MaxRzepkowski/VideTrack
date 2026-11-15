<?php

use App\Actions\Library\DuplicateList;
use App\Models\Company;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function customList(User $user, string $title = 'Favourite directors'): MediaList
{
    /** @var MediaList */
    return $user->lists()->create(['title' => $title]);
}

test('a person joins custom lists through the endpoint, never the watchlist', function () {
    Http::fake(['*/person/6384*' => Http::response(jsonFixture('tmdb/person'))]);

    $user = User::factory()->create();
    $list = customList($user);
    $watchlist = $user->watchlist();

    $this->actingAs($user)
        ->putJson(route('ui.people.lists.update', ['provider' => 'tmdb', 'ref' => '6384']), ['list_ids' => [$list->id, $watchlist->id]])
        ->assertSuccessful();

    $person = Person::query()->where('provider', 'tmdb')->where('provider_ref', '6384')->firstOrFail();

    expect(ListItem::query()->where('person_id', $person->id)->pluck('list_id')->all())->toBe([$list->id]);

    // The full-set contract removes the person when the set empties.
    $this->actingAs($user)
        ->putJson(route('ui.people.lists.update', ['provider' => 'tmdb', 'ref' => '6384']), ['list_ids' => []])
        ->assertSuccessful();

    expect(ListItem::query()->where('person_id', $person->id)->count())->toBe(0);
});

test('the membership endpoint reports the person\'s current lists as JSON', function () {
    $user = User::factory()->create();
    $list = customList($user);
    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);

    $list->items()->create(['person_id' => $person->id, 'position' => 1]);

    $this->actingAs($user)
        ->getJson(route('ui.people.lists.show', ['provider' => 'tmdb', 'ref' => '6384']))
        ->assertOk()
        ->assertJsonPath('data.listIds', [$list->id]);

    // An unpersisted person simply has no memberships.
    $this->actingAs($user)
        ->getJson(route('ui.people.lists.show', ['provider' => 'tmdb', 'ref' => '99999']))
        ->assertOk()
        ->assertJsonPath('data.listIds', []);
});

test('a studio or a network joins custom lists under its own kind', function (string $kind, string $show, string $update, string $ref) {
    Http::fake([
        "*/{$kind}/{$ref}*" => Http::response(['id' => (int) $ref, 'name' => 'Maker', 'logo_path' => '/logo.png']),
        '*/discover/*' => Http::response(['results' => [], 'total_pages' => 1]),
    ]);

    $user = User::factory()->create();
    $list = customList($user, 'Studios');

    $this->actingAs($user)
        ->putJson(route($update, ['provider' => 'tmdb', 'ref' => $ref]), ['list_ids' => [$list->id]])
        ->assertJsonPath('data.listIds', [$list->id])
        // The answer carries the creator's state, so the page needs no reload.
        ->assertJsonPath("creatorStates.{$kind}:tmdb:{$ref}", ['followed' => false, 'listIds' => [$list->id]]);

    $company = Company::query()->where('provider_ref', $ref)->sole();

    expect($company->kind)->toBe($kind);

    $this->actingAs($user)
        ->getJson(route($show, ['provider' => 'tmdb', 'ref' => $ref]))
        ->assertJsonPath('data.listIds', [$list->id]);
})->with([
    'studio' => ['company', 'ui.companies.lists.show', 'ui.companies.lists.update', '41077'],
    'network' => ['network', 'ui.networks.lists.show', 'ui.networks.lists.update', '49'],
]);

test('a malformed membership body is refused before anything is fetched or written', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->putJson(route('ui.people.lists.update', ['provider' => 'tmdb', 'ref' => '6384']), ['list_ids' => 'x'])
        ->assertUnprocessable();

    Http::assertNothingSent();
    expect(Person::query()->count())->toBe(0);
});

test('the list page serves person positions as person summaries, mixed with media', function () {
    $user = User::factory()->create();
    $list = customList($user);
    $movie = MediaItem::factory()->movie()->create();
    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);

    $list->items()->create(['media_item_id' => $movie->id, 'position' => 1]);
    $list->items()->create(['person_id' => $person->id, 'position' => 2]);

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn ($page) => $page
            ->component('lists/ListDetails')
            ->has('items', 2)
            ->where('items.1.media.type', 'person')
            ->where('items.1.media.title', 'Keanu Reeves')
            ->where('items.1.media.provider', 'tmdb')
            // The person cards' active state reads this membership map.
            ->where('creatorStates.person:tmdb:6384.listIds', [$list->id]));
});

test('duplicating a list carries person positions along', function () {
    $user = User::factory()->create();
    $list = customList($user);
    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);

    $list->items()->create(['person_id' => $person->id, 'position' => 1]);

    $copy = app(DuplicateList::class)->execute($user, $list);

    expect($copy->items()->first()?->person_id)->toBe($person->id);
});

test('duplicating a list carries company positions along', function () {
    $user = User::factory()->create();
    $list = customList($user);
    $company = Company::factory()->create(['provider' => 'igdb', 'kind' => 'company', 'provider_ref' => '70', 'name' => 'Nintendo']);

    $list->items()->create(['company_id' => $company->id, 'position' => 1]);

    $copy = app(DuplicateList::class)->execute($user, $list);

    expect($copy->items()->first()?->company_id)->toBe($company->id);
});

test('person positions survive the export round-trip shape', function () {
    $user = User::factory()->create();
    $list = customList($user);
    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);

    $list->items()->create(['person_id' => $person->id, 'position' => 1]);

    $export = backupOf($user);
    $items = collect($export['lists'])->firstWhere('title', 'Favourite directors')['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['type'])->toBe('person')
        ->and($items[0]['ref'])->toBe('6384')
        ->and($items[0]['title'])->toBe('Keanu Reeves');
});
