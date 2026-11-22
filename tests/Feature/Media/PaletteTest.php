<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Actions\Library\SetFavorite;
use App\Actions\Library\SetFollow;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Library\SyncListMembership;
use App\Catalog\Data\MediaSummary;
use App\Enums\FollowMode;
use App\Enums\MediaUserStatus;
use App\Http\Controllers\Media\PaletteController;
use App\Library\RecentViews;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

function paletteView(string $type, string $ref, string $title): MediaSummary
{
    return new MediaSummary(type: $type, ref: $ref, provider: 'tmdb', title: $title, posterPath: "/{$ref}.jpg");
}

/** A title in the user's library: persisted and watched by them. */
function watchedByUser(User $user, string $title): MediaItem
{
    $movie = MediaItem::factory()->movie()->create(['title' => $title]);

    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    return $movie;
}

test('the palette endpoint is closed to guests', function () {
    $this->get(route('palette'))->assertRedirect(route('login'));
});

test('an empty query answers with the history head', function () {
    $user = User::factory()->create();
    $views = app(RecentViews::class);

    $viewed = PaletteController::LIMIT + 2;

    foreach (range(1, $viewed) as $i) {
        $this->travel(1)->minutes();
        $views->record($user, paletteView('movie', (string) $i, "Movie {$i}"));
    }

    $response = $this->actingAs($user)->getJson(route('palette'))->assertOk();

    // Newest first, and the tail past the limit is cut.
    expect($response->json('results'))->toBe([])
        ->and(collect($response->json('recent'))->pluck('ref')->all())
        ->toBe(array_map(strval(...), range($viewed, 3)));
});

test('the history is scoped to the asking user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $views = app(RecentViews::class);

    $views->record($user, paletteView('movie', '603', 'The Matrix'));
    $views->record($other, paletteView('movie', '11', 'Star Wars'));

    $response = $this->actingAs($user)->getJson(route('palette'))->assertOk();

    expect(collect($response->json('recent'))->pluck('ref')->all())->toBe(['603']);
});

test('a query searches the local library and never calls a provider', function () {
    Http::fake();

    $user = User::factory()->create();
    $movie = watchedByUser($user, 'The Matrix');

    $response = $this->actingAs($user)->getJson(route('palette', ['q' => 'matr']))->assertOk();

    expect($response->json('recent'))->toBe([])
        // A whole summary, the same shape as every other card's.
        ->and($response->json('results.0'))->toMatchArray([
            'type' => 'movie',
            'ref' => $movie->provider_ref,
            'provider' => 'tmdb',
            'title' => 'The Matrix',
            'year' => $movie->year,
            'posterPath' => $movie->poster_path,
        ]);

    Http::assertNothingSent();
});

test('a title nobody tracks stays out of the palette', function () {
    $user = User::factory()->create();

    watchedByUser($user, 'The Matrix');
    // Persisted (someone browsed to it) but linked to no user.
    MediaItem::factory()->movie()->create(['title' => 'Matrix Revolutions']);

    $response = $this->actingAs($user)->getJson(route('palette', ['q' => 'matrix']))->assertOk();

    expect(collect($response->json('results'))->pluck('title')->all())->toBe(['The Matrix']);
});

test('another account library is not searchable', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    watchedByUser($other, 'The Matrix');

    $response = $this->actingAs($user)->getJson(route('palette', ['q' => 'matrix']))->assertOk();

    expect($response->json('results'))->toBe([]);
});

test('every kind of link counts as being in the library', function (string $link) {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['title' => 'The Matrix']);

    match ($link) {
        'play' => app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01'))),
        'rating' => app(RateItem::class)->execute($user, $movie, 8),
        'favorite' => app(SetFavorite::class)->execute($user, $movie, true),
        'list' => app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]),
        'status' => app(SetMediaStatus::class)->execute($user, $movie, MediaUserStatus::OnHold),
        'follow' => app(SetFollow::class)->execute($user, $movie, FollowMode::Follow),
    };

    $response = $this->actingAs($user)->getJson(route('palette', ['q' => 'matrix']))->assertOk();

    expect($response->json('results'))->toHaveCount(1);
})->with(['play', 'rating', 'favorite', 'list', 'status', 'follow']);

test('seasons and episodes never surface, only the show', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $show->update(['title' => 'Matrix Chronicles']);
    $show->children()->firstOrFail()->update(['title' => 'Matrix Chronicles season 1']);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $response = $this->actingAs($user)->getJson(route('palette', ['q' => 'matrix']))->assertOk();

    expect(collect($response->json('results'))->pluck('title')->all())->toBe(['Matrix Chronicles']);
});

test('prefix matches come before matches inside the title', function () {
    $user = User::factory()->create();

    watchedByUser($user, 'Dual Matrix');
    watchedByUser($user, 'Matrix Reloaded');

    $response = $this->actingAs($user)->getJson(route('palette', ['q' => 'matrix']))->assertOk();

    expect(collect($response->json('results'))->pluck('title')->all())->toBe(['Matrix Reloaded', 'Dual Matrix']);
});

test('results are cut to the palette limit', function () {
    $user = User::factory()->create();

    foreach (range(1, PaletteController::LIMIT + 4) as $i) {
        watchedByUser($user, "Matrix {$i}");
    }

    $response = $this->actingAs($user)->getJson(route('palette', ['q' => 'matrix']))->assertOk();

    expect($response->json('results'))->toHaveCount(PaletteController::LIMIT);
});

test('a wildcard typed into the box is a literal', function () {
    $user = User::factory()->create();

    watchedByUser($user, '100% Wolf');
    watchedByUser($user, 'The Matrix');

    $response = $this->actingAs($user)->getJson(route('palette', ['q' => '100%']))->assertOk();

    expect(collect($response->json('results'))->pluck('title')->all())->toBe(['100% Wolf']);
});

test('a query finds the lists it names: the viewer\'s own first, then the collections, never someone else\'s', function () {
    $user = User::factory()->create(['username' => 'alex']);
    $weekend = MediaList::factory()->for($user)->create(['title' => 'Weekend films']);
    MediaList::factory()->for($user)->create(['title' => 'Comfort shows']);
    MediaList::factory()->create(['title' => 'Weekend picks of someone else']);
    $saga = MediaList::factory()->collection('tmdb', '119')->create(['title' => 'The Weekend Saga']);

    $lists = $this->actingAs($user)->getJson(route('palette', ['q' => 'weekend']))->assertOk()->json('lists');

    expect($lists)->toBe([
        ['id' => $weekend->id, 'title' => 'Weekend films', 'href' => route('users.lists.show', ['user' => 'alex', 'list' => $weekend->id], absolute: false), 'itemCount' => 0, 'isCollection' => false],
        ['id' => $saga->id, 'title' => 'The Weekend Saga', 'href' => route('collections.show', ['provider' => 'tmdb', 'ref' => '119'], absolute: false), 'itemCount' => 0, 'isCollection' => true],
    ]);
});

test('the Backlog is found by the name it shows, and an empty query offers no lists', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson(route('palette', ['q' => 'backl']))
        ->assertJsonPath('lists.0.id', $user->watchlist()->id)
        ->assertJsonPath('lists.0.title', 'Backlog');

    $this->actingAs($user)->getJson(route('palette'))->assertJsonPath('lists', []);
});

test('the lists the palette offers stay within its limit, the viewer\'s own taking the room first', function () {
    $user = User::factory()->create();
    MediaList::factory()->for($user)->count(PaletteController::LIMIT)->create(['title' => 'Saga night']);
    MediaList::factory()->collection('tmdb', '10')->create(['title' => 'Saga collection']);

    $lists = $this->actingAs($user)->getJson(route('palette', ['q' => 'saga']))->json('lists');

    expect($lists)->toHaveCount(PaletteController::LIMIT)
        ->and(array_column($lists, 'isCollection'))->not->toContain(true);
});
