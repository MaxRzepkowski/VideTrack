<?php

use App\Actions\Media\MaterializeCollection;
use App\Catalog\Data\CollectionLink;
use App\Catalog\Data\CollectionRelations;
use App\Enums\TaskRunStatus;
use App\Jobs\RefreshCollections;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('the database rejects a duplicate ownerless collection list', function () {
    MediaList::factory()->create(['user_id' => null, 'title' => 'A', 'system_key' => 'collection:tmdb:99']);

    // The partial unique index closes the NULL-user_id gap that the composite
    // unique(user_id, system_key) leaves open.
    expect(fn () => MediaList::factory()->create(['user_id' => null, 'title' => 'B', 'system_key' => 'collection:tmdb:99']))
        ->toThrow(QueryException::class);
});

/**
 * @param  list<array{id: int, title: string, release_date: string}>  $parts
 * @return array<string, mixed>
 */
function tmdbCollection(array $parts = []): array
{
    return [
        'id' => 2344,
        'name' => 'The Matrix Collection',
        'overview' => 'The saga of Neo.',
        'poster_path' => '/collection-poster.jpg',
        'backdrop_path' => '/collection-backdrop.jpg',
        // A saga carries a gallery like a title does, on the same sub-request.
        'images' => [
            'backdrops' => [['file_path' => '/gallery-backdrop-1.jpg'], ['file_path' => '/gallery-backdrop-2.jpg']],
            'posters' => [['file_path' => '/gallery-poster-1.jpg']],
        ],
        'parts' => $parts !== [] ? $parts : [
            // Deliberately out of release order — the list must sort them.
            ['id' => 604, 'title' => 'The Matrix Reloaded', 'poster_path' => '/m2.jpg', 'release_date' => '2003-05-15'],
            ['id' => 603, 'title' => 'The Matrix', 'poster_path' => '/m1.jpg', 'release_date' => '1999-03-30'],
            ['id' => 605, 'title' => 'The Matrix Revolutions', 'poster_path' => '/m3.jpg', 'release_date' => '2003-11-05'],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function collectionHttpFakes(): array
{
    return [
        '*/collection/2344*' => Http::response(tmdbCollection()),
        '*/movie/603*' => Http::response([...jsonFixture('tmdb/movie'), 'belongs_to_collection' => [
            'id' => 2344, 'name' => 'The Matrix Collection',
            'poster_path' => '/collection-poster.jpg', 'backdrop_path' => '/collection-backdrop.jpg',
        ]]),
        '*/movie/604*' => Http::response(['id' => 604, 'title' => 'The Matrix Reloaded', 'poster_path' => '/m2.jpg', 'release_date' => '2003-05-15']),
        '*/movie/605*' => Http::response(['id' => 605, 'title' => 'The Matrix Revolutions', 'poster_path' => '/m3.jpg', 'release_date' => '2003-11-05']),
    ];
}

test('the movie page carries a banner for its collection', function () {
    Http::fake(providerDictionaryFakes());

    Http::fake(collectionHttpFakes());

    $this->actingAs(User::factory()->create())
        ->get(route('movie.details', ['ref' => '603']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/MovieDetails')
            ->where('collection.provider', 'tmdb')
            ->where('collection.ref', '2344')
            ->where('collection.name', 'The Matrix Collection')
            ->where('collection.backdropPath', '/collection-backdrop.jpg')
            ->where('collection.partCount', 3)->where('collection.partType', 'movie'));
});

test('a movie without a collection carries no banner', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    $this->actingAs(User::factory()->create())
        ->get(route('movie.details', ['ref' => '603']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('collection', null));
});

test('visiting a collection materializes an ownerless system list in release order', function () {
    Http::fake(providerDictionaryFakes());

    Http::fake(collectionHttpFakes());

    $response = $this->actingAs(User::factory()->create())
        ->get(route('collections.show', ['provider' => 'tmdb', 'ref' => '2344']));

    $list = MediaList::query()->collections()->sole();

    // The page stays at the provider address: the list id is never in it.
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('lists/ListDetails')
        ->where('list.id', $list->id)
        ->where('list.collection', ['provider' => 'tmdb', 'ref' => '2344']));

    expect($list->user_id)->toBeNull()
        ->and($list->system_key)->toBe('collection:tmdb:2344')
        ->and($list->title)->toBe('The Matrix Collection')
        ->and($list->description)->toBe('The saga of Neo.')
        ->and($list->backdrop_path)->toBe('/collection-backdrop.jpg')
        ->and($list->is_public)->toBeTrue()
        ->and($list->items()->with('mediaItem')->get()->pluck('mediaItem.title')->all())
        ->toBe(['The Matrix', 'The Matrix Reloaded', 'The Matrix Revolutions']);
});

test('visiting a book series materializes its main volumes in their numbering', function () {
    enableBooksModule();
    fakeHardcoverSeries(jsonFixture('hardcover/series'));

    $this->actingAs(User::factory()->create())
        ->get(route('collections.show', ['provider' => 'hardcover', 'ref' => '127396']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('lists/ListDetails')
            ->where('list.collection', ['provider' => 'hardcover', 'ref' => '127396']));

    $list = MediaList::query()->collections()->sole();

    expect($list->title)->toBe('Middle Earth')
        ->and($list->items()->orderBy('position')->with('mediaItem')->get()->pluck('mediaItem.title')->all())
        ->toBe(['The Silmarillion', 'The Hobbit, or There and Back Again', 'The Fellowship of the Ring', 'The Two Towers']);
});

test('a part TMDB has no poster or date for yet stays in the collection, last', function () {
    Http::fake(providerDictionaryFakes());
    Http::fake([
        '*/collection/2344*' => Http::response(tmdbCollection([
            ['id' => 606, 'title' => 'The Matrix: Untitled', 'poster_path' => null, 'release_date' => ''],
            ['id' => 603, 'title' => 'The Matrix', 'poster_path' => '/m1.jpg', 'release_date' => '1999-03-30'],
        ])),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        '*/movie/606*' => Http::response(['id' => 606, 'title' => 'The Matrix: Untitled', 'poster_path' => null, 'release_date' => '']),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('collections.show', ['provider' => 'tmdb', 'ref' => '2344']));

    $items = MediaList::query()->collections()->sole()->items()->with('mediaItem')->get();

    expect($items->pluck('mediaItem.title')->all())->toBe(['The Matrix', 'The Matrix: Untitled'])
        ->and($items->last()?->mediaItem?->poster_path)->toBeNull();
});

test('the collection list page renders with VideTrack as the author', function () {
    Http::fake(providerDictionaryFakes());

    Http::fake(collectionHttpFakes());

    $user = User::factory()->create();

    $this->actingAs($user)->get(route('collections.show', ['provider' => 'tmdb', 'ref' => '2344']));

    $list = MediaList::query()->collections()->sole();

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('lists/ListDetails')
            ->where('list.owner', null)
            ->where('list.isOwner', false)
            ->where('list.canDelete', false)
            ->where('list.backdropPath', '/collection-backdrop.jpg')
            ->has('items', 3));
});

test('the collection list page carries the saga gallery', function () {
    Http::fake(providerDictionaryFakes());
    Http::fake(collectionHttpFakes());

    $user = User::factory()->create();

    $this->actingAs($user)->get(route('collections.show', ['provider' => 'tmdb', 'ref' => '2344']));

    $list = MediaList::query()->collections()->sole();

    expect($list->images)->toBe([
        'backdrops' => ['/gallery-backdrop-1.jpg', '/gallery-backdrop-2.jpg'],
        'posters' => ['/gallery-poster-1.jpg'],
    ]);

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('list.backdrops', ['/gallery-backdrop-1.jpg', '/gallery-backdrop-2.jpg'])
            ->where('list.posters', ['/gallery-poster-1.jpg']));
});

test('a list a user typed out carries no gallery', function () {
    $user = User::factory()->create();
    $list = MediaList::factory()->create(['user_id' => $user->id, 'title' => 'Autumn films']);

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('list.backdrops', [])
            ->where('list.posters', []));
});

test('a second visit serves the existing list without re-syncing', function () {
    Http::fake(collectionHttpFakes());

    $user = User::factory()->create();

    $this->actingAs($user)->get(route('collections.show', ['provider' => 'tmdb', 'ref' => '2344']));

    Http::fake([...providerDictionaryFakes(), '*/collection/*' => Http::response([], 500)]);

    $this->actingAs($user)
        ->get(route('collections.show', ['provider' => 'tmdb', 'ref' => '2344']))
        ->assertOk();

    expect(MediaList::query()->collections()->count())->toBe(1);
});

test('nobody can edit or delete a collection list', function () {
    Http::fake(providerDictionaryFakes());
    Http::fake(collectionHttpFakes());

    $user = User::factory()->create();

    $this->actingAs($user)->get(route('collections.show', ['provider' => 'tmdb', 'ref' => '2344']));

    $list = MediaList::query()->collections()->sole();

    $this->actingAs($user)
        ->patchJson(route('ui.lists.update', $list), ['title' => 'Hijacked', 'is_public' => false])
        ->assertNotFound();

    $this->actingAs($user)->deleteJson(route('ui.lists.destroy', $list))->assertNotFound();
});

test('an unknown provider yields a 404', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('collections.show', ['provider' => 'nope', 'ref' => '1']))
        ->assertNotFound();
});

test('the refresh job picks up newly released parts', function () {
    Http::fake([
        ...collectionHttpFakes(),
        // First fetch (materialization) serves 3 parts, the next (refresh
        // job) a grown collection.
        '*/collection/2344*' => Http::sequence()
            ->push(tmdbCollection())
            ->push(tmdbCollection([
                ['id' => 603, 'title' => 'The Matrix', 'poster_path' => '/m1.jpg', 'release_date' => '1999-03-30'],
                ['id' => 604, 'title' => 'The Matrix Reloaded', 'poster_path' => '/m2.jpg', 'release_date' => '2003-05-15'],
                ['id' => 605, 'title' => 'The Matrix Revolutions', 'poster_path' => '/m3.jpg', 'release_date' => '2003-11-05'],
                ['id' => 624860, 'title' => 'The Matrix Resurrections', 'poster_path' => '/m4.jpg', 'release_date' => '2021-12-22'],
            ])),
        '*/movie/624860*' => Http::response(['id' => 624860, 'title' => 'The Matrix Resurrections', 'poster_path' => '/m4.jpg', 'release_date' => '2021-12-22']),
        ...providerDictionaryFakes(),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)->get(route('collections.show', ['provider' => 'tmdb', 'ref' => '2344']));

    expect(MediaList::query()->collections()->sole()->items()->count())->toBe(3);

    // The weekly job runs long after the provider's response cache expired.
    Cache::flush();

    RefreshCollections::queueTask();

    $list = MediaList::query()->collections()->sole();

    expect($list->items()->with('mediaItem')->get()->pluck('mediaItem.title')->all())
        ->toBe(['The Matrix', 'The Matrix Reloaded', 'The Matrix Revolutions', 'The Matrix Resurrections'])
        ->and(TaskRun::query()->where('name', 'refresh-collections')->sole()->status)->toBe(TaskRunStatus::Success);
});

test('a collection whose part breaks the sync is not left half written', function () {
    Http::fake([
        '*/collection/2344*' => Http::response(tmdbCollection()),
        '*/movie/603*' => fn () => throw new RuntimeException('The part could not be read.'),
    ]);

    $this->withoutExceptionHandling();

    expect(fn () => app(MaterializeCollection::class)->execute('tmdb', '2344', 'en'))->toThrow(RuntimeException::class)
        ->and(MediaList::query()->collections()->count())->toBe(0);
});

test('a collection gone from the provider is removed', function () {
    // A materialized TMDB collection whose upstream now 404s.
    $list = MediaList::factory()->create([
        'user_id' => null,
        'system_key' => MediaList::collectionKey('tmdb', '999'),
        'title' => 'Dead Collection',
        'is_public' => true,
    ]);

    Http::fake(['*' => Http::response([], 404)]);

    RefreshCollections::queueTask();

    $run = TaskRun::query()->where('name', 'refresh-collections')->firstOrFail();

    expect($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->summary)->toContain('Removed 1 no longer at the provider')
        // The removed collection is listed too, as a note rather than an error.
        ->and($run->errors)->toBeNull()
        ->and($run->details)->toHaveCount(1)
        ->and($run->details[0])->toContain('Dead Collection', 'removed')
        // The stale list is gone; its cascade cleaned list_items too.
        ->and(MediaList::query()->whereKey($list->id)->exists())->toBeFalse();
});

test("a series' page names the series around it, a plain collection none", function () {
    $relations = new CollectionRelations(
        parent: new CollectionLink('183', 'Tomb Raider'),
        subseries: [new CollectionLink('9300', 'Tomb Raider: Legend')],
        spinOffSeries: [new CollectionLink('9400', 'Lara Croft')],
    );
    $series = MediaList::factory()->create([
        'user_id' => null,
        'title' => 'Tomb Raider: Survivor',
        'system_key' => MediaList::collectionKey('igdb', '9209'),
        'collection_relations' => $relations->toStored(),
    ]);
    $matrix = MediaList::factory()->create(['user_id' => null, 'title' => 'The Matrix Collection', 'system_key' => MediaList::collectionKey('tmdb', '2344')]);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->get(listPage($series))
        ->assertInertia(fn (Assert $page) => $page
            ->where('list.collectionRelations.parent.name', 'Tomb Raider')
            ->where('list.collectionRelations.subseries.0.ref', '9300')
            ->where('list.collectionRelations.spinOffSeries.0.name', 'Lara Croft'));

    $this->actingAs($viewer)->get(listPage($matrix))
        ->assertInertia(fn (Assert $page) => $page->where('list.collectionRelations', null));
});

test("a list's game card wears the badge of its kind, as the provider's cards do", function () {
    enableGamesModule();
    $remake = MediaItem::factory()->game()->create(['title' => 'Resident Evil 4', 'metadata' => ['gameType' => 'remake']]);
    $game = MediaItem::factory()->game()->create(['title' => 'Resident Evil Village', 'metadata' => null]);
    $series = MediaList::factory()->create(['user_id' => null, 'title' => 'Resident Evil', 'system_key' => MediaList::collectionKey('igdb', '83')]);
    $series->items()->create(['media_item_id' => $remake->id, 'position' => 1]);
    $series->items()->create(['media_item_id' => $game->id, 'position' => 2]);

    $this->actingAs(User::factory()->create())->get(listPage($series))
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.media.extra.gameType', 'remake')
            ->missing('items.1.media.extra.gameType'));
});

test('a collection has no address under a user, nor under its list number', function () {
    $collection = MediaList::factory()->collection('tmdb', '2344')->create(['title' => 'The Matrix Collection']);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->get(route('users.lists.show', ['user' => $viewer->username, 'list' => $collection->id]))->assertNotFound();
    $this->actingAs($viewer)->get("/list/{$collection->id}")->assertNotFound();
    $this->actingAs($viewer)->get(route('collections.show', ['provider' => 'tmdb', 'ref' => '2344']))->assertOk();
});
