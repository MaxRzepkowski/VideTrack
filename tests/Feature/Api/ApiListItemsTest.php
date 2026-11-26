<?php

use App\Actions\Library\AddToList;
use App\Actions\Library\CreateList;
use App\Http\Requests\Api\V1\AddListItemsRequest;
use App\Library\Data\ListTarget;
use App\Library\Events\ListItemAdded;
use App\Library\ListAddOutcome;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/*
 * Many titles onto one list in one call: each goes on in the order asked,
 * and the answer says what became of each.
 */

function listWriter(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);

    return $user;
}

/** @return array{type: string, ref: string} */
function itemOf(MediaItem $item): array
{
    return ['type' => $item->type, 'ref' => $item->provider_ref];
}

/** @return list<int> */
function listOrder(MediaList $list): array
{
    return $list->items()->orderBy('position')->pluck('media_item_id')->all();
}

test('titles go on in the order asked, after what the list holds already', function () {
    $user = listWriter();
    $list = app(CreateList::class)->execute($user, 'Marvel');
    [$first, $second, $third, $fourth] = MediaItem::factory()->movie()->count(4)->create()->all();
    app(AddToList::class)->execute($user, $list, ListTarget::item($first->id));

    $this->postJson(route('api.v1.lists.items.store', $list), ['items' => [itemOf($third), itemOf($second), itemOf($fourth)]])
        ->assertOk()
        ->assertJsonPath('data', [
            [...itemOf($third), 'outcome' => ListAddOutcome::Added->value],
            [...itemOf($second), 'outcome' => ListAddOutcome::Added->value],
            [...itemOf($fourth), 'outcome' => ListAddOutcome::Added->value],
        ])
        ->assertJsonPath('meta.added', 3)
        ->assertJsonPath('meta.itemsCount', 4)
        ->assertJsonPath("states.movie:{$third->provider_ref}.listIds", [$list->id]);

    expect(listOrder($list))->toBe([$first->id, $third->id, $second->id, $fourth->id]);
});

test('a title on the list already stays where it is, and one asked twice goes on once', function () {
    $user = listWriter();
    $list = app(CreateList::class)->execute($user, 'Marvel');
    [$there, $new] = MediaItem::factory()->movie()->count(2)->create()->all();
    app(AddToList::class)->execute($user, $list, ListTarget::item($there->id));
    Event::fake([ListItemAdded::class]);

    $this->postJson(route('api.v1.lists.items.store', $list), ['items' => [itemOf($new), itemOf($there), itemOf($new)]])
        ->assertOk()
        ->assertJsonPath('data', [
            [...itemOf($new), 'outcome' => ListAddOutcome::Added->value],
            [...itemOf($there), 'outcome' => ListAddOutcome::Present->value],
        ])
        ->assertJsonPath('meta.added', 1)
        ->assertJsonPath('meta.present', 1)
        ->assertJsonPath("states.movie:{$there->provider_ref}.listIds", [$list->id]);

    expect(listOrder($list))->toBe([$there->id, $new->id]);
    Event::assertDispatchedTimes(ListItemAdded::class, 1);
});

test('a title nobody stored is fetched from its provider, one it does not know is passed over', function () {
    Http::fake([
        ...providerDictionaryFakes(),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        '*/movie/999999*' => Http::response(['status_code' => 34, 'status_message' => 'The resource you requested could not be found.'], 404),
    ]);
    $list = app(CreateList::class)->execute(listWriter(), 'Marvel');

    $this->postJson(route('api.v1.lists.items.store', $list), ['items' => [['type' => 'movie', 'ref' => '999999'], ['type' => 'movie', 'ref' => '603']]])
        ->assertOk()
        ->assertJsonPath('data.0.outcome', ListAddOutcome::NotFound->value)
        ->assertJsonPath('data.1.outcome', ListAddOutcome::Added->value)
        ->assertJsonPath('meta.notFound', 1)
        ->assertJsonPath('meta.itemsCount', 1);

    expect(MediaItem::query()->where('provider_ref', '603')->exists())->toBeTrue();
});

test('once the provider does not answer, the titles it would have to fetch are not asked about', function () {
    Http::fake([...providerDictionaryFakes(), '*/movie/*' => Http::response([], 500)]);
    $list = app(CreateList::class)->execute(listWriter(), 'Marvel');
    $stored = MediaItem::factory()->movie()->create();

    $this->postJson(route('api.v1.lists.items.store', $list), ['items' => [
        ['type' => 'movie', 'ref' => '603'],
        ['type' => 'movie', 'ref' => '604'],
        itemOf($stored),
    ]])
        ->assertOk()
        ->assertJsonPath('data.0.outcome', ListAddOutcome::Unavailable->value)
        ->assertJsonPath('data.1.outcome', ListAddOutcome::Unavailable->value)
        ->assertJsonPath('data.2.outcome', ListAddOutcome::Added->value)
        ->assertJsonPath('meta.unavailable', 2);

    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/movie/604')))->toBeEmpty()
        ->and(listOrder($list))->toBe([$stored->id]);
});

test("one provider's outage keeps no other provider's titles off the list", function () {
    enableGamesModule();
    Http::fake([
        ...providerDictionaryFakes(),
        '*/movie/*' => Http::response([], 500),
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'))),
    ]);
    $list = app(CreateList::class)->execute(listWriter(), 'Played and watched');

    $this->postJson(route('api.v1.lists.items.store', $list), ['items' => [['type' => 'movie', 'ref' => '603'], ['type' => 'game', 'ref' => '7346']]])
        ->assertOk()
        ->assertJsonPath('data.0.outcome', ListAddOutcome::Unavailable->value)
        ->assertJsonPath('data.1.outcome', ListAddOutcome::Added->value);
});

test('a season and an episode go on a list as themselves', function () {
    $list = app(CreateList::class)->execute(listWriter(), 'Rewatch');
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $season = seasonOf($show, 1);
    $episode = episodeOf($show, 1, 2);

    $this->postJson(route('api.v1.lists.items.store', $list), ['items' => [itemOf($season), itemOf($episode)]])
        ->assertOk()
        ->assertJsonPath('meta.added', 2);

    expect(listOrder($list))->toBe([$season->id, $episode->id]);
});

test("someone else's list cannot be added to, nor with a read token", function () {
    $movie = MediaItem::factory()->movie()->create();
    listWriter();
    $theirs = app(CreateList::class)->execute(User::factory()->create(), 'Theirs', isPublic: false);

    $this->postJson(route('api.v1.lists.items.store', $theirs), ['items' => [itemOf($movie)]])->assertNotFound();

    $reader = User::factory()->create();
    Sanctum::actingAs($reader, ['read']);
    $own = app(CreateList::class)->execute($reader, 'Mine');

    $this->postJson(route('api.v1.lists.items.store', $own), ['items' => [itemOf($movie)]])->assertForbidden();
    expect($theirs->items()->count() + $own->items()->count())->toBe(0);
});

test('a call names between one and the most titles, each by a type and a ref', function (array $items, string $field) {
    $list = app(CreateList::class)->execute(listWriter(), 'Marvel');

    $this->postJson(route('api.v1.lists.items.store', $list), ['items' => $items])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'none' => [[], 'items'],
    'one too many' => [array_fill(0, AddListItemsRequest::MAX_ITEMS + 1, ['type' => 'movie', 'ref' => '603']), 'items'],
    'a type the instance does not have' => [[['type' => 'painting', 'ref' => '603']], 'items.0.type'],
    'a ref that is no ref' => [[['type' => 'movie', 'ref' => '../603']], 'items.0.ref'],
    'no ref' => [[['type' => 'movie']], 'items.0.ref'],
]);

test('adding is charged per title, apart from looking titles up', function () {
    Http::fake(['*/search/multi*' => Http::response(jsonFixture('tmdb/search'))]);
    $list = app(CreateList::class)->execute(listWriter(), 'Marvel');
    $items = array_map(itemOf(...), MediaItem::factory()->movie()->count(AddListItemsRequest::MAX_ITEMS)->create()->all());
    $allowed = intdiv(config()->integer('tracker.rate_limits.provider_pages'), AddListItemsRequest::MAX_ITEMS);

    foreach (range(1, $allowed) as $call) {
        $this->postJson(route('api.v1.lists.items.store', $list), ['items' => $items])->assertOk();
    }

    $this->postJson(route('api.v1.lists.items.store', $list), ['items' => $items])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
    $this->postJson(route('api.v1.lookup'), ['titles' => [['title' => 'The Matrix', 'type' => 'movie']]])->assertOk();
});
