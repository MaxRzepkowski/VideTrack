<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Suggestions\BuildShelfPage;
use App\Actions\Suggestions\BuildSuggestionsPage;
use App\Library\WatchedAt;
use App\Models\CatalogSetTitle;
use App\Models\Follow;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

test('guests are sent to sign in', function () {
    $this->get(route('suggestions.index'))->assertRedirect(route('login'));
});

test('an account with nothing to suggest gets a page without shelves', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('suggestions.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Suggestions')->where('shelves', []));
});

// No provider is called: a stray request fails the test (tests/Pest.php).
test('the page lists the kinds that have something, with how much', function () {
    Notification::fake();
    $user = User::factory()->create();
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '1']), WatchedAt::now());
    collectionSet('10', ['1' => '2001-01-01', '2' => '2003-01-01', '3' => '2004-01-01'], 'The Saga');

    $this->actingAs($user)
        ->get(route('suggestions.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('shelves', 1)
            ->where('shelves.0.key', 'missing-from-collections')
            ->where('shelves.0.title', 'Missing from collections')
            ->where('shelves.0.total', 2)
            // The collection has no poster of its own: its first missing part lends one.
            ->where('shelves.0.posters', ['/poster.jpg']));

    Notification::assertNothingSent();
});

test('a kind\'s tile and its collection cards show the collection\'s own poster', function () {
    $user = User::factory()->create();
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '1']), WatchedAt::now());
    $set = collectionSet('10', ['1' => '2001-01-01', '2' => '2003-01-01', '3' => '2004-01-01']);
    $set->titles()->where('ref', '2')->update(['card' => ['posterPath' => '/two.jpg']]);
    $set->titles()->where('ref', '3')->update(['card' => ['posterPath' => '/three.jpg']]);

    // A collection without a poster of its own borrows its first missing title's.
    $this->actingAs($user)
        ->get(route('suggestions.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('shelves.0.posters', ['/two.jpg']));

    $set->update(['poster_path' => '/saga.jpg']);

    // The tiles are remembered for a while; a later look counts again.
    $this->actingAs($user)
        ->get(route('suggestions.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('shelves.0.posters', ['/two.jpg']));

    $this->travel(BuildSuggestionsPage::KINDS_REMEMBERED_SECONDS + 1)->seconds();

    $this->actingAs($user)
        ->get(route('suggestions.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('shelves.0.posters', ['/saga.jpg']));

    $this->actingAs($user)
        ->get(route('suggestions.show', ['shelf' => 'missing-from-collections']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('subjects.0.posterPath', '/saga.jpg'));
});

test('a kind with subjects lists its collections, not their titles', function () {
    $user = User::factory()->create();
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '1']), WatchedAt::now());
    collectionSet('10', ['1' => '2001-01-01', '2' => '2003-01-01', '3' => '2004-01-01'], 'The Saga');

    $this->actingAs($user)
        ->get(route('suggestions.show', ['shelf' => 'missing-from-collections']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('suggestions/Shelf')
            ->where('shelf.title', 'Missing from collections')
            ->where('subject', null)
            ->where('subjects', [['key' => 'tmdb-10', 'title' => 'The Saga', 'caption' => '1 of 3 watched', 'finished' => 1, 'total' => 3, 'posterPath' => '/poster.jpg', 'count' => 2]])
            ->where('suggestions', [])
            ->where('types', [['type' => 'movie', 'count' => 2]])
            ->where('pagination', ['currentPage' => 1, 'totalPages' => 1, 'total' => 2]));
});

test('a collection\'s page lists its missing titles with their states', function () {
    $user = User::factory()->create();
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '1']), WatchedAt::now());
    collectionSet('10', ['1' => '2001-01-01', '2' => '2003-01-01'], 'The Saga');

    $this->actingAs($user)
        ->get(route('suggestions.subject', ['shelf' => 'missing-from-collections', 'subject' => 'tmdb-10']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('subject', ['key' => 'tmdb-10', 'title' => 'The Saga', 'caption' => '1 of 2 watched', 'href' => route('collections.show', ['provider' => 'tmdb', 'ref' => '10'], false)])
            ->where('subjects', null)
            ->where('suggestions.0.media.ref', '2')
            ->has('userStates.movie:2'));

    $this->actingAs($user)
        ->get(route('suggestions.subject', ['shelf' => 'missing-from-collections', 'subject' => 'tmdb-999']))
        ->assertNotFound();
});

test('a kind without subjects pages its titles and narrows to a type', function () {
    $user = User::factory()->create();

    foreach (range(1, BuildShelfPage::TITLES_PER_PAGE + 1) as $day) {
        $film = MediaItem::factory()->movie()->create(['release_date' => today()->subDays($day)->toDateString()]);
        Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $film->id, 'created_at' => '2020-01-01 00:00:00']);
    }

    $show = MediaItem::factory()->show()->create(['release_date' => today()->subDay()->toDateString()]);
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $show->id, 'created_at' => '2020-01-01 00:00:00']);

    $this->actingAs($user)
        ->get(route('suggestions.show', ['shelf' => 'released-since-added', 'page' => 2]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('suggestions', 2)
            ->where('subjects', null)
            ->where('pagination.totalPages', 2));

    $this->actingAs($user)
        ->get(route('suggestions.show', ['shelf' => 'released-since-added', 'type' => 'show']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('suggestions', 1)
            ->where('filters.type', 'show')
            ->where('pagination.total', 1)
            // The filter's options count the whole kind, whichever type is picked.
            ->has('types', 2)
            ->has('userStates'));
});

test('a shelf with several ways in counts them and narrows to one', function () {
    $user = User::factory()->create();
    $belled = MediaItem::factory()->movie()->create(['title' => 'Belled', 'release_date' => today()->subDays(2)->toDateString()]);
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $belled->id, 'created_at' => '2020-01-01 00:00:00']);
    $listed = MediaItem::factory()->movie()->create(['title' => 'Listed', 'release_date' => today()->subDay()->toDateString()]);
    ListItem::factory()->create(['list_id' => MediaList::factory()->create(['user_id' => $user->id])->id, 'media_item_id' => $listed->id, 'created_at' => '2020-01-01 00:00:00']);

    $this->actingAs($user)
        ->get(route('suggestions.show', ['shelf' => 'released-since-added', 'origin' => 'listed']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('origins', [['key' => 'listed', 'label' => 'On a list', 'count' => 1], ['key' => 'followed', 'label' => 'Followed', 'count' => 1]])
            ->where('filters.origin', 'listed')
            ->has('suggestions', 1)
            ->where('suggestions.0.media.title', 'Listed'));

    // A way in the shelf does not have is no filter at all.
    $this->actingAs($user)
        ->get(route('suggestions.show', ['shelf' => 'released-since-added', 'origin' => 'nonsense']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.origin', null)->has('suggestions', 2));

    $this->actingAs($user)
        ->get(route('suggestions.show', ['shelf' => 'missing-from-collections']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('origins', []));
});

/**
 * Three collections the user has begun: Alpha (1 of 2 seen, missing a 2001
 * film), Bravo (1 of 4 seen, three missing, the newest from 2024) and Charlie
 * (2 of 3 seen, missing a 1985 film).
 */
function threeCollections(User $user): void
{
    foreach (['10' => ['1'], '20' => ['5'], '30' => ['9', '10']] as $collection => $seen) {
        foreach ($seen as $ref) {
            app(MarkWatched::class)->execute($user, filmOfCollection((string) $collection, ['provider_ref' => $ref]), WatchedAt::now());
        }
    }

    collectionSet('10', ['1' => '2000-01-01', '2' => '2001-01-01'], 'Alpha');
    collectionSet('20', ['5' => '2010-01-01', '6' => '2012-01-01', '7' => '2018-01-01', '8' => '2024-01-01'], 'Bravo');
    collectionSet('30', ['9' => '1980-01-01', '10' => '1982-01-01', '11' => '1985-01-01'], 'Charlie');
    CatalogSetTitle::query()->get()->each(fn (CatalogSetTitle $title) => $title->update(['card' => ['year' => $title->release_date?->year, 'posterPath' => '/poster.jpg']]));
}

test('collections come in the source\'s order and in any order the page offers', function (array $address, array $expected) {
    $user = User::factory()->create();
    threeCollections($user);

    $this->actingAs($user)
        ->get(route('suggestions.show', ['shelf' => 'missing-from-collections', ...$address]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('subjects', fn ($subjects) => collect($subjects)->pluck('title')->all() === $expected)
            ->where('sorts', ['suggested', 'remaining', 'progress', 'release', 'title']));
})->with([
    'fewest left, deepest in first' => [[], ['Charlie', 'Alpha', 'Bravo']],
    'most left to watch' => [['sort' => 'remaining', 'dir' => 'desc'], ['Bravo', 'Alpha', 'Charlie']],
    'newest missing title' => [['sort' => 'release', 'dir' => 'desc'], ['Bravo', 'Alpha', 'Charlie']],
    'furthest through' => [['sort' => 'progress', 'dir' => 'desc'], ['Charlie', 'Alpha', 'Bravo']],
    'by name' => [['sort' => 'title'], ['Alpha', 'Bravo', 'Charlie']],
    'an order titles have and collections do not falls back' => [['sort' => 'rating'], ['Charlie', 'Alpha', 'Bravo']],
]);

test('collections are searched by name or by a missing title, and narrowed to a decade', function () {
    $user = User::factory()->create();
    threeCollections($user);
    $shelf = ['shelf' => 'missing-from-collections'];

    $this->actingAs($user)
        ->get(route('suggestions.show', [...$shelf, 'q' => 'brav']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.q', 'brav')
            // Named by the search, so it keeps all it puts forward.
            ->where('subjects', fn ($subjects) => collect($subjects)->map(fn ($subject) => [$subject['title'], $subject['count']])->all() === [['Bravo', 3]]));

    $this->actingAs($user)
        ->get(route('suggestions.show', [...$shelf, 'decade' => 2010]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.decade', 2010)
            ->where('subjects', fn ($subjects) => collect($subjects)->map(fn ($subject) => [$subject['title'], $subject['count']])->all() === [['Bravo', 2]])
            ->where('pagination.total', 2)
            // Counted before any filter, the latest decade first.
            ->where('decades', [['decade' => 2020, 'count' => 1], ['decade' => 2010, 'count' => 2], ['decade' => 2000, 'count' => 1], ['decade' => 1980, 'count' => 1]]));
});

test('titles sort by release, by the day they were added, by rating and by name', function (array $address, array $expected) {
    $user = User::factory()->create();

    foreach ([['Bee', '2026-05-01', '2026-01-10', 6.0], ['Ant', '2026-09-01', '2026-03-10', 8.0], ['Cat', '2026-07-01', '2026-02-10', 7.0]] as [$title, $released, $added, $rating]) {
        $film = MediaItem::factory()->movie()->create(['title' => $title, 'release_date' => $released, 'community_rating' => $rating]);
        Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $film->id, 'created_at' => "{$added} 10:00:00"]);
    }

    $this->actingAs($user)
        ->get(route('suggestions.show', ['shelf' => 'released-since-added', ...$address]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('suggestions', fn ($suggestions) => collect($suggestions)->pluck('media.title')->all() === $expected)
            ->where('sorts', ['suggested', 'release', 'added', 'rating', 'title']));
})->with([
    'the source\'s order: latest premiere first' => [[], ['Ant', 'Cat', 'Bee']],
    'oldest release first' => [['sort' => 'release'], ['Bee', 'Cat', 'Ant']],
    'added longest ago' => [['sort' => 'added'], ['Bee', 'Cat', 'Ant']],
    'best rated' => [['sort' => 'rating', 'dir' => 'desc'], ['Ant', 'Cat', 'Bee']],
    'by name' => [['sort' => 'title'], ['Ant', 'Bee', 'Cat']],
    'searched' => [['q' => 'ca'], ['Cat']],
]);

test('an unknown shelf is not found', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('suggestions.show', ['shelf' => 'no-such-shelf']))
        ->assertNotFound();
});
