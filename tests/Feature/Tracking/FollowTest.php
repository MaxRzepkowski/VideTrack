<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Library\SyncListMembership;
use App\Actions\Media\SyncCreatorTitles;
use App\Catalog\MediaItemSummaries;
use App\Enums\FollowMode;
use App\Jobs\SyncFollowedCreator;
use App\Library\LibraryStates;
use App\Library\WatchedAt;
use App\Models\Company;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use App\Tracking\Surfaces\CalendarDays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

function followCalendarDays(User $user): array
{
    return app(CalendarDays::class)->range(
        $user,
        today()->toDateString(),
        today()->addDays(365)->toDateString(),
    );
}

test('following a movie creates the row and the release shows on the calendar', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['release_date' => today()->addDays(10)->toDateString()]);

    $this->actingAs($user)
        ->putJson(route('ui.media.follow.update', ['type' => 'movie', 'ref' => $movie->provider_ref]), ['mode' => 'follow'])
        ->assertSuccessful();

    expect(Follow::query()->where('user_id', $user->id)->where('media_item_id', $movie->id)->firstOrFail()->mode)
        ->toBe(FollowMode::Follow);

    $days = followCalendarDays($user);

    expect($days)->toHaveCount(1)
        ->and($days[0]->entries[0]->media->title)->toBe($movie->title);
});

test('the bell endpoint rejects non-root positions', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => [today()->addDay()->toDateString()]]);
    $season = $show->children()->firstOrFail();

    $this->actingAs($user)
        ->putJson(route('ui.media.follow.update', ['type' => 'season', 'ref' => $season->provider_ref]), ['mode' => 'follow'])
        ->assertStatus(422);
});

test('clearing the bell removes the exception row', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'mode' => FollowMode::Mute]);

    $this->actingAs($user)
        ->putJson(route('ui.media.follow.update', ['type' => 'movie', 'ref' => $movie->provider_ref]), ['mode' => null])
        ->assertSuccessful();

    expect(Follow::query()->count())->toBe(0);
});

test('a followed show contributes episodes without any play or backlog spot', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', today()->addDays(5)->toDateString()]]);

    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $show->id, 'mode' => FollowMode::Follow]);

    $days = followCalendarDays($user);

    expect($days)->toHaveCount(1)
        ->and($days[0]->date)->toBe(today()->addDays(5)->toDateString());

    // The "followed manually" source can be toggled off per consumer.
    $user->preferences = ['calendar_followed' => false];
    $user->save();

    expect(followCalendarDays($user->refresh()))->toBe([]);
});

test('muting a watched show silences its episodes everywhere', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', today()->addDays(5)->toDateString()]]);

    app(MarkWatched::class)->execute(
        $user,
        $show->children()->firstOrFail()->children()->where('number', 1)->firstOrFail(),
        WatchedAt::at(CarbonImmutable::parse('2026-01-01')),
    );

    expect(followCalendarDays($user))->toHaveCount(1);

    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $show->id, 'mode' => FollowMode::Mute]);

    expect(followCalendarDays($user))->toBe([]);
});

test('muting silences a watchlisted movie and its watchlisted season alike', function () {
    $user = User::factory()->create();

    $movie = MediaItem::factory()->movie()->create(['release_date' => today()->addDays(3)->toDateString()]);
    $show = makeShowTree([1 => [today()->addDays(4)->toDateString()]]);
    $season = $show->children()->firstOrFail();

    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);
    app(SyncListMembership::class)->execute($user, $season, [$user->watchlist()->id]);

    expect(followCalendarDays($user))->toHaveCount(2);

    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'mode' => FollowMode::Mute]);
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $show->id, 'mode' => FollowMode::Mute]);

    expect(followCalendarDays($user))->toBe([]);
});

test('the active source toggle spares backlog positions', function () {
    $user = User::factory()->create();

    // One show tracked by plays alone, one movie tracked by the backlog.
    $show = makeShowTree([1 => ['2020-01-01', today()->addDays(5)->toDateString()]]);

    app(MarkWatched::class)->execute(
        $user,
        $show->children()->firstOrFail()->children()->where('number', 1)->firstOrFail(),
        WatchedAt::at(CarbonImmutable::parse('2026-01-01')),
    );

    $movie = MediaItem::factory()->movie()->create(['release_date' => today()->addDays(3)->toDateString()]);
    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);

    expect(followCalendarDays($user))->toHaveCount(2);

    $user->preferences = ['calendar_active' => false];
    $user->save();

    $days = followCalendarDays($user->refresh());

    expect($days)->toHaveCount(1)
        ->and($days[0]->entries[0]->media->title)->toBe($movie->title);
});

test('the library state carries the bell mode', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'mode' => FollowMode::Follow]);

    $states = app(LibraryStates::class)->resolve($user, [MediaItemSummaries::summary($movie)]);

    expect($states["movie:{$movie->provider_ref}"]->follow)->toBe(FollowMode::Follow);
});

test('following a person persists them and the page reports the state', function () {
    Http::fake(['*/person/6384*' => Http::response(jsonFixture('tmdb/person'))]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow'])
        ->assertSuccessful();

    $person = Person::query()->where('provider', 'tmdb')->where('provider_ref', '6384')->firstOrFail();

    expect($person->name)->toBe('Keanu Reeves')
        ->and(Follow::query()->where('person_id', $person->id)->where('mode', FollowMode::Follow->value)->count())->toBe(1);

    // Unfollow removes the exception but keeps the person row.
    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => null])
        ->assertSuccessful();

    expect(Follow::query()->count())->toBe(0)
        ->and(Person::query()->count())->toBe(1);
});

test('following a studio or a network persists it under its kind', function (string $kind, string $route, string $ref) {
    Http::fake([
        "*/{$kind}/{$ref}*" => Http::response(['id' => (int) $ref, 'name' => 'Maker', 'logo_path' => '/logo.png']),
        '*/discover/*' => Http::response(['results' => [], 'total_pages' => 1]),
    ]);
    Bus::fake([SyncFollowedCreator::class]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route($route, ['provider' => 'tmdb', 'ref' => $ref]), ['mode' => 'follow'])
        ->assertSuccessful()
        ->assertJsonPath('data.mode', 'follow')
        ->assertJsonPath("creatorStates.{$kind}:tmdb:{$ref}.followed", true);

    $company = Company::query()->where('provider_ref', $ref)->sole();

    expect($company->kind)->toBe($kind)
        ->and(Follow::query()->where('company_id', $company->id)->where('mode', FollowMode::Follow->value)->count())->toBe(1);
    Bus::assertDispatched(SyncFollowedCreator::class);

    $this->actingAs($user)
        ->putJson(route($route, ['provider' => 'tmdb', 'ref' => $ref]), ['mode' => null])
        ->assertSuccessful()
        ->assertJsonPath('data.mode', null);

    expect(Follow::query()->count())->toBe(0);
})->with([
    'studio' => ['company', 'ui.companies.follow.update', '41077'],
    'network' => ['network', 'ui.networks.follow.update', '49'],
]);

test('unfollowing a studio nobody ever followed asks no provider', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->putJson(route('ui.companies.follow.update', ['provider' => 'tmdb', 'ref' => '41077']), ['mode' => null])
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(Company::query()->count())->toBe(0);
});

test('a creator at a provider that serves no such page is a quiet 404', function () {
    Exceptions::fake();

    $this->actingAs(User::factory()->create())
        ->putJson(route('ui.networks.follow.update', ['provider' => 'igdb', 'ref' => '1']), ['mode' => 'follow'])
        ->assertNotFound();

    $this->actingAs(User::factory()->create())
        ->get(route('network.details', ['provider' => 'igdb', 'ref' => '1']))
        ->assertNotFound();

    Exceptions::assertNothingReported();
    expect(Company::query()->count())->toBe(0);
});

test('a creator sync that could not reach its provider says why in words, not as a key', function () {
    Http::fake(['*' => Http::response(null, 503)]);
    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);
    Follow::query()->create(['user_id' => User::factory()->create()->id, 'person_id' => $person->id, 'mode' => FollowMode::Follow]);

    $result = app(SyncCreatorTitles::class)->execute($person);

    expect($result->errors)->toHaveCount(1)
        ->and($result->errors[0])->toStartWith('person:6384 (Keanu Reeves): ')
        ->and($result->errors[0])->not->toContain('catalog.')
        ->and($result->errors[0])->not->toContain('import.');
});
