<?php

use App\Actions\Library\AddToList;
use App\Actions\Library\CreateList;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Library\Data\ListTarget;
use App\Library\Events\PlayRecorded;
use App\Library\MutationSource;
use App\Library\RatingScale;
use App\Library\WatchedAt;
use App\Models\CatalogEvent;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use App\Notifications\MediaEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Each tool over HTTP, the way an assistant calls it: through the token's
 * middleware, into API v1, answering compact.
 */
function assistant(array $abilities = ['read', 'write']): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user, $abilities);

    return $user;
}

/** The tool's result: `structuredContent` on success, `isError` and the sentence in `content` on a refusal. */
function mcpTool(string $name, array $arguments = []): TestResponse
{
    return test()->postJson(route('api.mcp'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $arguments],
    ])->assertOk();
}

function mcpRefusal(TestResponse $response): string
{
    $response->assertJsonPath('result.isError', true);

    return (string) $response->json('result.content.0.text');
}

// Reading

test('search answers a few compact cards: no pictures, nothing empty', function () {
    Http::fake(['*/search/multi*' => Http::response(jsonFixture('tmdb/search'))]);
    assistant(['read']);

    $response = mcpTool('search', ['query' => 'matrix', 'type' => 'movie', 'limit' => 1])
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.data.0.title', 'The Matrix')
        ->assertJsonPath('result.structuredContent.data.0.ref', '603')
        ->assertJsonPath('result.structuredContent.meta.found', 2);

    $card = $response->json('result.structuredContent.data.0');

    expect($response->json('result.structuredContent.data'))->toHaveCount(1)
        ->and($card)->not->toHaveKeys(['posterPath', 'posterUrl', 'backdropUrl'])
        // Nobody touched it: no state at all, a missing flag is false.
        ->and($card)->not->toHaveKey('state');
});

test('search finds people too, in their own catalogue', function () {
    Http::fake(['*/search/person*' => Http::response(['page' => 1, 'total_pages' => 1, 'results' => [
        ['id' => 6384, 'name' => 'Keanu Reeves', 'profile_path' => '/keanu.jpg', 'known_for_department' => 'Acting'],
    ]])]);
    assistant(['read']);

    mcpTool('search', ['query' => 'keanu', 'type' => 'person'])
        ->assertJsonPath('result.structuredContent.data.0.title', 'Keanu Reeves')
        ->assertJsonPath('result.structuredContent.data.0.ref', '6384');
});

test('search says the catalogue could not answer instead of finding nothing', function () {
    Http::fake(['*/search/multi*' => Http::response([], 503)]);
    assistant(['read']);

    expect(mcpRefusal(mcpTool('search', ['query' => 'matrix'])))->toBe(__('mcp.the_catalogue_could_not_answer'));
});

test('many titles are checked in one call, by name and year', function () {
    Http::fake(['*/search/multi*' => Http::response(jsonFixture('tmdb/search'))]);
    $user = assistant(['read']);
    $matrix = MediaItem::factory()->movie()->create(['provider_ref' => '603', 'title' => 'The Matrix']);
    app(RateItem::class)->execute($user, $matrix, 9);

    $checked = mcpTool('check_titles', ['titles' => [
        ['title' => 'the matrix', 'type' => 'movie', 'year' => 2000],
        ['title' => 'The Matrix', 'type' => 'movie', 'year' => 1970],
    ]])->json('result.structuredContent.data');

    // A year off is the same film: a premiere and a release straddle New Year.
    expect($checked[0]['media']['ref'])->toBe('603')
        ->and($checked[0]['media']['state']['rating'])->toBe(9)
        // Thirty years off is not.
        ->and($checked[1])->not->toHaveKey('media');
});

test('a title comes in full with the caller state', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);
    assistant(['read']);

    $title = mcpTool('get_title', ['type' => 'movie', 'ref' => '603'])->json('result.structuredContent.data');

    expect($title['overview'])->not->toBeEmpty()
        ->and($title['genres'])->not->toBeEmpty();
});

test('the library comes a page at a time, without links to follow', function () {
    $user = assistant(['read']);

    foreach (range(1, 3) as $index) {
        $movie = MediaItem::factory()->movie()->create(['title' => "Film {$index}"]);
        app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse("2026-0{$index}-01")));
    }

    $response = mcpTool('get_library', ['tab' => 'history', 'per_page' => 2, 'page' => 2])
        ->assertJsonPath('result.structuredContent.meta.total', 3)
        ->assertJsonPath('result.structuredContent.meta.currentPage', 2)
        ->assertJsonPath('result.structuredContent.data.0.title', 'Film 1')
        ->assertJsonPath('result.structuredContent.data.0.state.watched', true);

    expect($response->json('result.structuredContent'))->not->toHaveKey('links');
});

test("the user's tens come without paging through every rating", function () {
    $user = assistant(['read']);
    app(RateItem::class)->execute($user, MediaItem::factory()->show()->create(['title' => 'Best']), RatingScale::MAX);
    app(RateItem::class)->execute($user, MediaItem::factory()->show()->create(['title' => 'Fine']), 7);

    mcpTool('get_library', ['tab' => 'ratings', 'type' => 'show', 'min_rating' => RatingScale::MAX])
        ->assertJsonPath('result.structuredContent.meta.total', 1)
        ->assertJsonPath('result.structuredContent.data.0.title', 'Best');
});

test('up next names the first unwatched episode of each show', function () {
    $user = assistant(['read']);
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08', '2020-01-15']]);
    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::now());

    mcpTool('get_up_next')
        ->assertJsonPath('result.structuredContent.data.0.show.ref', $show->provider_ref)
        ->assertJsonPath('result.structuredContent.data.0.episode.number', 2)
        ->assertJsonPath('result.structuredContent.data.0.watchedEpisodes', 1)
        ->assertJsonPath('result.structuredContent.data.0.remainingEpisodes', 2);
});

test('an empty answer still says "none"', function () {
    assistant(['read']);

    mcpTool('get_up_next')->assertJsonPath('result.structuredContent.data', []);
});

test("the calendar starts on the caller's today and spans a week by default", function () {
    // Noon in Greenwich is two in the morning of the next day on Kiritimati.
    Sanctum::actingAs(User::factory()->create(['preferences' => ['timezone' => 'Pacific/Kiritimati']]), ['read']);

    mcpTool('get_calendar')
        ->assertJsonPath('result.structuredContent.meta.from', '2026-10-11')
        ->assertJsonPath('result.structuredContent.meta.to', '2026-10-17');
});

test('the calendar answers each day as the api does, compact', function () {
    enableGamesModule();
    assistant(['read']);
    CatalogEvent::factory()->create(['name' => 'Showcase', 'starts_at' => CarbonImmutable::parse('2026-10-14 18:00', 'UTC'), 'ends_at' => null]);

    $api = $this->getJson(route('api.v1.calendar', ['from' => '2026-10-10', 'to' => '2026-10-20']))->json();

    $event = mcpTool('get_calendar', ['from' => '2026-10-10', 'days' => 11])
        ->assertJsonPath('result.structuredContent.meta.to', $api['meta']['to'])
        ->assertJsonPath('result.structuredContent.data.0.date', $api['data'][0]['date'])
        ->json('result.structuredContent.data.0.events.0');

    expect($event['name'])->toBe('Showcase')
        // The same event, less its picture.
        ->and($api['data'][0]['events'][0])->toHaveKey('logoPath')
        ->and($event)->toHaveKeys(['provider', 'ref', 'name', 'startsAt'])
        ->and($event)->not->toHaveKey('logoPath');
});

test('the countdowns come soonest first, searched by name', function () {
    $user = assistant(['read']);

    foreach (['Dune' => '2026-10-12', 'Far Away' => '2030-05-01'] as $title => $date) {
        $movie = MediaItem::factory()->movie()->create(['title' => $title, 'release_date' => $date]);
        $user->watchlist()->items()->create(['media_item_id' => $movie->id, 'position' => 1]);
    }

    mcpTool('get_countdowns')
        ->assertJsonPath('result.structuredContent.data.0.media.title', 'Dune')
        ->assertJsonPath('result.structuredContent.data.1.media.releaseDate', '2030-05-01')
        ->assertJsonPath('result.structuredContent.meta.types.0', ['type' => 'movie', 'count' => 2]);

    mcpTool('get_countdowns', ['query' => 'far', 'type' => 'movie'])
        ->assertJsonPath('result.structuredContent.meta.total', 1)
        ->assertJsonPath('result.structuredContent.data.0.media.title', 'Far Away');
});

test('statistics come for a year, and a section on its own', function () {
    $user = assistant(['read']);
    $movie = MediaItem::factory()->movie()->create(['runtime' => 100]);
    Play::factory()->for($user)->for($movie, 'mediaItem')->create(['watched_at' => '2025-03-01 20:00:00']);
    projectLibrary($user);

    mcpTool('get_statistics', ['period' => '2025'])
        ->assertJsonPath('result.structuredContent.meta.period.from', '2025-01-01')
        ->assertJsonPath('result.structuredContent.data.summary.time.total', 100);

    mcpTool('get_statistics', ['period' => 'all', 'section' => 'taste'])
        ->assertJsonPath('result.structuredContent.data.top.0.amount', 100);

    expect(mcpRefusal(mcpTool('get_statistics', ['from' => '2025-01-01'])))->not->toBeEmpty();
});

test('trending titles come in their three sections, compact', function () {
    Http::fake([
        '*/trending/movie/week*' => Http::response(['results' => []]),
        '*/trending/tv/week*' => Http::response(['results' => [['id' => 1399, 'name' => 'Game of Thrones', 'poster_path' => '/g.jpg', 'first_air_date' => '2011-04-17']]]),
        '*/tv/popular*' => Http::response(['results' => []]),
        '*/tv/on_the_air*' => Http::response(['results' => []]),
        ...providerDictionaryFakes(),
    ]);
    assistant(['read']);

    $response = mcpTool('get_trending', ['type' => 'show'])
        ->assertJsonPath('result.structuredContent.data.trending.0.title', 'Game of Thrones')
        ->assertJsonPath('result.structuredContent.meta.type', 'show');

    expect($response->json('result.structuredContent.data.trending.0'))->not->toHaveKey('posterPath')
        // An empty section is left out, like every empty field.
        ->and($response->json('result.structuredContent.data'))->not->toHaveKey('popular')
        ->and($response->json('result.structuredContent.meta'))->not->toHaveKey('failed');
});

test('the history lists each viewing with the id that deletes it', function () {
    $user = assistant();
    $movie = MediaItem::factory()->movie()->create();
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-02-01')));

    $plays = mcpTool('get_history', ['type' => 'movie', 'ref' => $movie->provider_ref])->json('result.structuredContent.data');

    expect($plays)->toHaveCount(2);

    mcpTool('delete_history_entry', ['play_id' => $plays[0]['id']])
        ->assertJsonPath('result.structuredContent.data.state.plays', 1);
});

test('suggestions list their kinds, and none here is none', function () {
    assistant(['read']);

    mcpTool('get_suggestions')->assertJsonPath('result.structuredContent.data', []);
});

test('notifications are read and marked as read', function () {
    $user = assistant();
    $user->notify(new MediaEvent(premiereEvent('The Matrix', '603')));

    mcpTool('get_notifications')->assertJsonPath('result.structuredContent.meta.total', 1);
    mcpTool('mark_notifications_read')->assertJsonPath('result.structuredContent.done', true);

    expect($user->unreadNotifications()->count())->toBe(0);
});

// Writing

test('marking a title watched writes a play from the api and answers with its new state', function () {
    $user = assistant();
    $movie = MediaItem::factory()->movie()->create();

    mcpTool('mark_watched', ['type' => 'movie', 'ref' => $movie->provider_ref])
        ->assertJsonPath('result.structuredContent.meta.created', 1)
        ->assertJsonPath('result.structuredContent.data.state.watched', true)
        ->assertJsonPath("result.structuredContent.states.movie:{$movie->provider_ref}.plays", 1);

    expect(Play::query()->where('user_id', $user->id)->sole()->source)->toBe(MutationSource::api()->value());
});

test('a play marked by an assistant is announced as coming from the api', function () {
    Event::fake([PlayRecorded::class]);
    assistant();
    $movie = MediaItem::factory()->movie()->create();

    mcpTool('mark_watched', ['type' => 'movie', 'ref' => $movie->provider_ref]);

    Event::assertDispatched(PlayRecorded::class, fn (PlayRecorded $e): bool => $e->source->value() === MutationSource::api()->value());
});

test('a play can be dated, but not in the future: the api says why', function () {
    $user = assistant();
    $movie = MediaItem::factory()->movie()->create();

    expect(mcpRefusal(mcpTool('mark_watched', ['type' => 'movie', 'ref' => $movie->provider_ref, 'watched_at' => today()->addDay()->toDateString()])))
        ->toBe(__('app.pick_a_valid_date_no_later'));

    mcpTool('mark_watched', ['type' => 'movie', 'ref' => $movie->provider_ref, 'watched_at' => '2026-03-01T21:30']);

    expect(Play::query()->where('user_id', $user->id)->sole()->watched_at->toDateTimeString())->toBe('2026-03-01 21:30:00');
});

test('the next episode of a show is marked alone', function () {
    $user = assistant();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);

    mcpTool('mark_watched', ['type' => 'show', 'ref' => $show->provider_ref, 'next_episode' => true])
        ->assertJsonPath('result.structuredContent.data.type', 'episode')
        ->assertJsonPath('result.structuredContent.data.number', 1);

    expect(Play::query()->where('user_id', $user->id)->sole()->media_item_id)->toBe(episodeOf($show, 1, 1)->id);
});

test('unmarking takes the viewing back', function () {
    $user = assistant();
    $movie = MediaItem::factory()->movie()->create();
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::now());

    mcpTool('unmark_watched', ['type' => 'movie', 'ref' => $movie->provider_ref])
        ->assertJsonPath('result.structuredContent.meta.deleted', 1);

    expect(Play::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('a ref nobody serves comes back as a sentence, not a failure', function () {
    Http::fake(['*/movie/999999*' => Http::response(['status_code' => 34], 404), ...providerDictionaryFakes()]);
    $user = assistant();

    expect(mcpRefusal(mcpTool('mark_watched', ['type' => 'movie', 'ref' => '999999'])))->toBe(__('mcp.nothing_at_this_address'));
    expect(Play::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('a type the instance does not serve is refused before anything is asked', function () {
    assistant();

    mcpRefusal(mcpTool('mark_watched', ['type' => 'podcast', 'ref' => '1']));

    Http::assertNothingSent();
});

test('a rating replaces the earlier one, and is taken away on its own', function () {
    $user = assistant();
    $movie = MediaItem::factory()->movie()->create();
    app(RateItem::class)->execute($user, $movie, 4);

    mcpTool('rate', ['type' => 'movie', 'ref' => $movie->provider_ref, 'rating' => 9])
        ->assertJsonPath('result.structuredContent.data.state.rating', 9);

    mcpTool('remove_rating', ['type' => 'movie', 'ref' => $movie->provider_ref]);

    expect(Rating::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('a rating off the scale is refused', function () {
    $user = assistant();
    $movie = MediaItem::factory()->movie()->create();

    mcpRefusal(mcpTool('rate', ['type' => 'movie', 'ref' => $movie->provider_ref, 'rating' => RatingScale::MAX + 1]));

    expect(Rating::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('a title is put on hold and taken off it', function () {
    assistant();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);

    mcpTool('set_status', ['type' => 'show', 'ref' => $show->provider_ref, 'status' => 'on_hold'])
        ->assertJsonPath('result.structuredContent.data.state.status', 'on_hold');

    $state = mcpTool('set_status', ['type' => 'show', 'ref' => $show->provider_ref, 'status' => 'none'])
        ->json('result.structuredContent.data.state');

    expect($state)->not->toHaveKey('status');
});

test('a favorite and a bell are set on a title', function () {
    assistant();
    $movie = MediaItem::factory()->movie()->create();

    mcpTool('set_favorite', ['type' => 'movie', 'ref' => $movie->provider_ref, 'favorite' => true])
        ->assertJsonPath('result.structuredContent.data.state.favorite', true);

    mcpTool('follow_releases', ['type' => 'movie', 'ref' => $movie->provider_ref, 'mode' => 'follow'])
        ->assertJsonPath('result.structuredContent.data.state.follow', 'follow');
});

test('a creator is addressed where the api keeps its bell', function () {
    assistant();

    // Only a collection can be muted: the api's rule, reached at the person's address.
    expect(mcpRefusal(mcpTool('follow_creator', ['kind' => 'person', 'provider' => 'tmdb', 'ref' => '6384', 'mode' => 'mute'])))
        ->toBe(__('validation.in', ['attribute' => 'mode']));

    Http::assertNothingSent();
});

test('a finished show is started again and the rewatch cancelled', function () {
    $user = assistant();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    app(MarkWatched::class)->execute($user, $show, WatchedAt::now());

    mcpTool('start_rewatch', ['type' => 'show', 'ref' => $show->provider_ref])
        ->assertJsonPath('result.structuredContent.data.state.cycle', 2);

    // Back in the first run: the cycle a title starts in goes without saying.
    expect(mcpTool('cancel_rewatch', ['type' => 'show', 'ref' => $show->provider_ref])->json('result.structuredContent.data.state'))
        ->not->toHaveKey('cycle');
});

// Lists

test('a list is made, filled, emptied, renamed and deleted', function () {
    $user = assistant();
    $movie = MediaItem::factory()->movie()->create();

    $id = mcpTool('create_list', ['title' => 'Light films'])->json('result.structuredContent.data.id');

    mcpTool('add_to_list', ['type' => 'movie', 'ref' => $movie->provider_ref, 'list_id' => $id])
        ->assertJsonPath('result.structuredContent.data.state.listIds', [$id]);
    mcpTool('get_list', ['list_id' => $id])
        ->assertJsonPath('result.structuredContent.data.items.0.media.ref', $movie->provider_ref);

    mcpTool('remove_from_list', ['type' => 'movie', 'ref' => $movie->provider_ref, 'list_id' => $id]);
    expect(MediaList::query()->findOrFail($id)->items()->count())->toBe(0);

    // What the assistant left out stays as it was.
    $visibility = MediaList::query()->findOrFail($id)->is_public;

    mcpTool('update_list', ['list_id' => $id, 'title' => 'Light ones'])
        ->assertJsonPath('result.structuredContent.data.title', 'Light ones');

    expect(MediaList::query()->findOrFail($id)->is_public)->toBe($visibility);

    mcpTool('delete_list', ['list_id' => $id]);
    expect(MediaList::query()->whereKey($id)->exists())->toBeFalse();
});

test('adding to a list keeps the title on its other lists', function () {
    $user = assistant();
    $movie = MediaItem::factory()->movie()->create();
    $first = app(CreateList::class)->execute($user, 'First');
    $second = app(CreateList::class)->execute($user, 'Second');
    app(AddToList::class)->execute($user, $first, ListTarget::item($movie->id));

    mcpTool('add_to_list', ['type' => 'movie', 'ref' => $movie->provider_ref, 'list_id' => $second->id]);

    expect($movie->listItems()->pluck('list_id')->sort()->values()->all())->toBe([$first->id, $second->id]);
});

test("someone else's history entry is not the assistant's to delete", function () {
    assistant();
    $theirs = Play::factory()->create();
    projectEveryLibrary();

    mcpRefusal(mcpTool('delete_history_entry', ['play_id' => $theirs->id]));

    expect(Play::query()->whereKey($theirs->id)->exists())->toBeTrue();
});

test("someone else's list is not there", function () {
    assistant();
    $list = app(CreateList::class)->execute(User::factory()->create(), 'Theirs', isPublic: false);

    expect(mcpRefusal(mcpTool('get_list', ['list_id' => $list->id])))->toBe(__('mcp.nothing_at_this_address'));
});

test('progress is logged in the type\'s own unit', function () {
    enableBooksModule();
    fakeHardcover(['Details' => 'book']);
    assistant();

    mcpTool('log_progress', ['type' => 'book', 'ref' => '2459845', 'add' => 40])
        ->assertJsonPath('result.structuredContent.data.state.progress', 40);
});
