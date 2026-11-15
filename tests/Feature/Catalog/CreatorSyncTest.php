<?php

use App\Enums\FollowMode;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * What every followed creator does to the titles it has coming, whatever its
 * kind: the bell puts an auto-follow on the upcoming title and announces it
 * once, the daily sweep repeats that without duplicates, a credit that
 * vanishes takes its auto-follow away, a hand-made bell is never taken over,
 * and a title backed by two creators outlives losing one of them. Driven only
 * through the bell endpoint and the scheduled task, so it holds whatever the
 * sync looks like inside.
 */

/**
 * One TMDB world with a person, a studio and a network, each crediting one
 * upcoming title. Returns a switch that takes a creator's credit away.
 *
 * @return callable(string, bool): void
 */
function fakeCreatorWorld(): callable
{
    $present = ['person' => true, 'company' => true, 'network' => true];
    $soon = fn (): string => today()->addDays(30)->toDateString();

    Http::fake(function (Request $request) use (&$present, $soon) {
        $url = $request->url();

        if (str_contains($url, '/person/6384')) {
            $person = jsonFixture('tmdb/person');

            if ($present['person']) {
                $person['combined_credits']['cast'][] = [
                    'id' => 603, 'media_type' => 'movie', 'title' => 'Upcoming Movie', 'poster_path' => '/up.jpg',
                    'release_date' => $soon(), 'character' => 'Neo', 'popularity' => 999.0,
                ];
            }

            return Http::response($person);
        }

        if (str_contains($url, '/company/174')) {
            return Http::response(['id' => 174, 'name' => 'Warner Bros. Pictures', 'logo_path' => '/wb.png']);
        }

        if (str_contains($url, '/network/49')) {
            return Http::response(['id' => 49, 'name' => 'HBO', 'logo_path' => '/hbo.png']);
        }

        if (str_contains($url, '/discover/movie') && str_contains($url, 'with_companies=174')) {
            return Http::response(['results' => $present['company'] ? [
                ['id' => 603, 'title' => 'Upcoming Movie', 'poster_path' => '/up.jpg', 'release_date' => $soon()],
            ] : [], 'total_pages' => 1]);
        }

        if (str_contains($url, '/discover/tv') && str_contains($url, 'with_networks=49')) {
            return Http::response(['results' => $present['network'] ? [
                ['id' => 1399, 'name' => 'Upcoming Show', 'poster_path' => '/show.jpg', 'first_air_date' => $soon()],
            ] : [], 'total_pages' => 1]);
        }

        if (str_contains($url, '/discover/')) {
            return Http::response(['results' => [], 'total_pages' => 1]);
        }

        foreach (['/tv/1399/season/0' => 'season0', '/tv/1399/season/1' => 'season', '/tv/1399/season/2' => 'season2', '/tv/1399' => 'tv', '/movie/603' => 'movie'] as $path => $fixture) {
            if (str_contains($url, $path)) {
                return Http::response(jsonFixture("tmdb/{$fixture}"));
            }
        }

        foreach (providerDictionaryFakes() as $pattern => $response) {
            if (Str::is($pattern, $url)) {
                return $response;
            }
        }

        return Http::response(['status_message' => 'not found'], 404);
    });

    return function (string $kind, bool $value) use (&$present): void {
        $present[$kind] = $value;
        // A real sweep runs a day later, past the provider cache.
        Cache::flush();
    };
}

/**
 * @return array{route: string, ref: string, task: string, type: string, title: string, event: string}
 */
function creatorCase(string $kind): array
{
    return match ($kind) {
        'person' => ['route' => 'ui.people.follow.update', 'ref' => '6384', 'task' => 'refresh-followed-people', 'type' => 'movie', 'title' => '603', 'event' => 'person_new_title'],
        'company' => ['route' => 'ui.companies.follow.update', 'ref' => '174', 'task' => 'refresh-followed-companies', 'type' => 'movie', 'title' => '603', 'event' => 'company_new_title'],
        'network' => ['route' => 'ui.networks.follow.update', 'ref' => '49', 'task' => 'refresh-followed-companies', 'type' => 'show', 'title' => '1399', 'event' => 'company_new_title'],
    };
}

function bellCreator(User $user, string $kind, ?string $mode): void
{
    $case = creatorCase($kind);

    test()->actingAs($user)
        ->putJson(route($case['route'], ['provider' => 'tmdb', 'ref' => $case['ref']]), ['mode' => $mode])
        ->assertSuccessful();
}

function creatorTitle(string $kind): MediaItem
{
    $case = creatorCase($kind);

    return MediaItem::query()->where('type', $case['type'])->where('provider_ref', $case['title'])->sole();
}

function titleFollow(User $user, MediaItem $item): ?Follow
{
    return $user->follows()->where('media_item_id', $item->id)->first();
}

test('following a creator puts a bell on its upcoming title and announces it once', function (string $kind) {
    fakeCreatorWorld();
    $user = User::factory()->create();

    bellCreator($user, $kind, 'follow');
    runScheduledTask(creatorCase($kind)['task']);

    $follow = titleFollow($user, creatorTitle($kind));

    expect($follow?->mode)->toBe(FollowMode::Follow)
        ->and($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->sole()->data['event'])->toBe(creatorCase($kind)['event']);

    runScheduledTask(creatorCase($kind)['task']);

    expect($user->follows()->whereNotNull('media_item_id')->count())->toBe(1)
        ->and($user->notifications()->count())->toBe(1);
})->with(['person', 'company', 'network']);

test('unfollowing a creator takes its auto-follow away', function (string $kind) {
    fakeCreatorWorld();
    $user = User::factory()->create();

    bellCreator($user, $kind, 'follow');
    $title = creatorTitle($kind);

    bellCreator($user, $kind, null);

    expect(titleFollow($user, $title))->toBeNull();
})->with(['person', 'company', 'network']);

test('a hand-made bell on the title is never taken over by a creator', function (string $kind) {
    fakeCreatorWorld();
    $user = User::factory()->create();

    bellCreator($user, $kind, 'follow');
    $title = creatorTitle($kind);

    // The user rings the title's own bell: the row becomes theirs.
    $this->actingAs($user)
        ->putJson(route('ui.media.follow.update', ['type' => $title->type, 'ref' => $title->provider_ref]), ['mode' => 'follow'])
        ->assertSuccessful();

    runScheduledTask(creatorCase($kind)['task']);
    bellCreator($user, $kind, null);

    expect(titleFollow($user, $title)?->mode)->toBe(FollowMode::Follow);
})->with(['person', 'company', 'network']);

test('a credit that vanishes takes its upcoming auto-follow away on the next sweep', function (string $kind) {
    $setCredit = fakeCreatorWorld();
    $user = User::factory()->create();

    bellCreator($user, $kind, 'follow');
    $title = creatorTitle($kind);
    // The details fixture carries a past date; an upcoming title has a future one.
    $title->update(['release_date' => today()->addDays(30)]);

    $setCredit($kind, false);
    runScheduledTask(creatorCase($kind)['task']);

    expect(titleFollow($user, $title))->toBeNull()
        ->and($user->follows()->whereNull('media_item_id')->count())->toBe(1);
})->with(['person', 'company', 'network']);

test('a muted title keeps its mute through the sweep and gets its auto-follow back when unmuted', function (string $kind) {
    fakeCreatorWorld();
    $user = User::factory()->create();

    bellCreator($user, $kind, 'follow');
    $title = creatorTitle($kind);
    $bell = route('ui.media.follow.update', ['type' => $title->type, 'ref' => $title->provider_ref]);

    $this->actingAs($user)->putJson($bell, ['mode' => 'mute'])->assertSuccessful();
    runScheduledTask(creatorCase($kind)['task']);

    expect(titleFollow($user, $title)?->mode)->toBe(FollowMode::Mute);

    $this->actingAs($user)->putJson($bell, ['mode' => null])->assertSuccessful();

    expect(titleFollow($user, $title)?->mode)->toBe(FollowMode::Follow);
})->with(['person', 'company', 'network']);

test('a title two creators back outlives losing either of them', function (string $first, string $second) {
    fakeCreatorWorld();
    $user = User::factory()->create();

    bellCreator($user, $first, 'follow');
    bellCreator($user, $second, 'follow');
    runScheduledTask(creatorCase($first)['task']);
    runScheduledTask(creatorCase($second)['task']);

    $title = creatorTitle($first);

    bellCreator($user, $first, null);

    expect(titleFollow($user, $title)?->mode)->toBe(FollowMode::Follow);

    bellCreator($user, $second, null);

    expect(titleFollow($user, $title))->toBeNull();
})->with([
    'person, then studio' => ['person', 'company'],
    'studio, then person' => ['company', 'person'],
]);

test('a title a creator and a followed saga both back outlives unfollowing the creator', function () {
    fakeCreatorWorld();
    $user = User::factory()->create();

    bellCreator($user, 'person', 'follow');
    $title = creatorTitle('person');
    $title->update(['release_date' => today()->addDays(30)]);

    // The saga is materialized with the same film as its upcoming part.
    $saga = MediaList::factory()->create([
        'user_id' => null,
        'system_key' => MediaList::collectionKey('tmdb', '2344'),
        'title' => 'The Saga',
        'is_public' => true,
    ]);
    $saga->items()->create(['media_item_id' => $title->id, 'position' => 1]);

    $this->actingAs($user)
        ->putJson(route('ui.collections.follow.update', ['provider' => 'tmdb', 'ref' => '2344']), ['mode' => 'follow'])
        ->assertSuccessful();

    bellCreator($user, 'person', null);

    expect(titleFollow($user, $title)?->mode)->toBe(FollowMode::Follow);

    $this->actingAs($user)
        ->putJson(route('ui.collections.follow.update', ['provider' => 'tmdb', 'ref' => '2344']), ['mode' => null])
        ->assertSuccessful();

    expect(titleFollow($user, $title))->toBeNull();
});
