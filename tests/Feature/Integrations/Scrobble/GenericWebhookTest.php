<?php

use App\Enums\WebhookDeliveryStatus;
use App\Integrations\Generic\GenericScrobbleSource;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The generic webhook's format is a public contract: scripts are written
 * against it. So these read like its documentation — each accepted shape,
 * and each refusal with the reason the script's author gets back.
 */
function genericEndpoint(?User $user = null): WebhookEndpoint
{
    return scrobbleEndpoint($user ?? User::factory()->create(), GenericScrobbleSource::CODE);
}

/**
 * @param  array<string, mixed>  $payload
 */
function postGeneric(WebhookEndpoint $endpoint, array $payload): TestResponse
{
    return test()->postJson("/webhooks/generic/{$endpoint->token}", $payload);
}

function lastGenericDelivery(): WebhookDelivery
{
    return WebhookDelivery::query()->latest('id')->firstOrFail();
}

/**
 * @param  array<string, mixed>  $media
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function genericPayload(string $event, array $media, array $extra = []): array
{
    return ['event' => $event, 'media' => $media, ...$extra];
}

beforeEach(function () {
    Http::fake([
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        '*/tv/1399/season/1/episode/1*' => Http::response(jsonFixture('tmdb/episode')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399*' => tmdbShowAnswer(seasons: [1 => jsonFixture('tmdb/season')]),
    ]);
});

test('a film watched is a play, and the same post again is one delivery', function () {
    Http::fake(providerDictionaryFakes());

    $user = User::factory()->create();
    $endpoint = genericEndpoint($user);
    $payload = genericPayload('complete', ['kind' => 'movie', 'ids' => ['tmdb' => 603], 'title' => 'The Matrix', 'year' => 1999]);

    postGeneric($endpoint, $payload)->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::where('user_id', $user->id)->count())->toBe(1)
        ->and(lastGenericDelivery()->status)->toBe(WebhookDeliveryStatus::Accepted)
        ->and(lastGenericDelivery()->media_label)->toBe('The Matrix (1999)');

    postGeneric($endpoint, $payload)->assertNoContent();

    expect(lastGenericDelivery()->message)->toBe('duplicate_delivery')
        ->and(Play::where('user_id', $user->id)->count())->toBe(1);
});

test('an episode is placed by its show ids, season and number', function () {
    Http::fake(providerDictionaryFakes());

    $user = User::factory()->create();

    postGeneric(genericEndpoint($user), genericPayload('complete', [
        'kind' => 'episode',
        'ids' => ['tmdb' => '1399'],
        'season' => 1,
        'episode' => 1,
        'title' => 'Game of Thrones',
    ]))->assertStatus(Response::HTTP_ACCEPTED);

    $play = Play::query()->with('mediaItem')->firstOrFail();

    expect($play->mediaItem->provider_ref)->toBe('1399:1:1')
        ->and(lastGenericDelivery()->media_label)->toBe('Game of Thrones S01E01');
});

test('a stop past the threshold counts as watched, one short of it does not', function () {
    Http::fake(providerDictionaryFakes());

    $user = User::factory()->create();
    $endpoint = genericEndpoint($user);
    $movie = ['kind' => 'movie', 'ids' => ['tmdb' => 603]];

    postGeneric($endpoint, genericPayload('stop', $movie, ['progress' => 40]))->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::count())->toBe(0)
        ->and(lastGenericDelivery()->message)->toBe('below_threshold');

    postGeneric($endpoint, genericPayload('stop', $movie, ['progress' => 92.5]))->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::where('user_id', $user->id)->count())->toBe(1);
});

test('progress on a book sets the page by its own id', function () {
    enableBooksModule();

    $user = User::factory()->create();
    $book = MediaItem::factory()->book()->create(['provider_ref' => '1032534']);

    postGeneric(genericEndpoint($user), genericPayload('progress', [
        'kind' => 'book',
        'ids' => ['hardcover' => 1032534],
    ], ['amount' => 120]))->assertStatus(Response::HTTP_ACCEPTED);

    expect(MediaUserState::where('user_id', $user->id)->where('media_item_id', $book->id)->value('progress'))->toBe(120)
        ->and(lastGenericDelivery()->status)->toBe(WebhookDeliveryStatus::Accepted);
});

test('progress on a game is minutes, and a percentage alone places a manga chapter', function () {
    enableGamesModule();
    enableMangaModule();

    $user = User::factory()->create();
    $endpoint = genericEndpoint($user);
    $game = MediaItem::factory()->game()->create(['provider_ref' => '1942']);
    $manga = MediaItem::factory()->manga()->create(['provider_ref' => '2', 'metadata' => ['chapters' => 200]]);

    postGeneric($endpoint, genericPayload('progress', ['kind' => 'game', 'ids' => ['igdb' => 1942]], ['amount' => 95]))
        ->assertStatus(Response::HTTP_ACCEPTED);
    postGeneric($endpoint, genericPayload('progress', ['kind' => 'manga', 'ids' => ['mal' => 2]], ['progress' => 25]))
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(MediaUserState::where('media_item_id', $game->id)->value('progress'))->toBe(95)
        ->and(MediaUserState::where('media_item_id', $manga->id)->value('progress'))->toBe(50);
});

test('progress on a film has nowhere to go and says so', function () {
    Http::fake(providerDictionaryFakes());

    postGeneric(genericEndpoint(), genericPayload('progress', ['kind' => 'movie', 'ids' => ['tmdb' => 603]], ['amount' => 40]))
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(lastGenericDelivery()->status)->toBe(WebhookDeliveryStatus::Ignored)
        ->and(lastGenericDelivery()->message)->toBe('no_progress_unit')
        ->and(MediaUserState::count())->toBe(0);
});

test('a rating is carried over', function () {
    Http::fake(providerDictionaryFakes());

    $user = User::factory()->create();

    postGeneric(genericEndpoint($user), genericPayload('rate', ['kind' => 'movie', 'ids' => ['tmdb' => 603]], ['rating' => 9]))
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(Rating::where('user_id', $user->id)->value('rating'))->toBe(9)
        ->and(Play::count())->toBe(0);
});

test('the moment it happened is the moment of the play', function () {
    Http::fake(providerDictionaryFakes());

    $user = User::factory()->create();

    postGeneric(genericEndpoint($user), genericPayload(
        'complete',
        ['kind' => 'movie', 'ids' => ['tmdb' => 603]],
        ['occurred_at' => '2026-01-02T20:15:00Z'],
    ))->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::where('user_id', $user->id)->firstOrFail()->watched_at->toIso8601ZuluString())->toBe('2026-01-02T20:15:00Z');
});

test('unknown fields are ignored, so a newer script still works', function () {
    Http::fake(providerDictionaryFakes());

    postGeneric(genericEndpoint(), genericPayload(
        'complete',
        ['kind' => 'movie', 'ids' => ['tmdb' => 603], 'poster' => 'x.jpg'],
        ['version' => 1, 'client' => 'my-script/2.0'],
    ))->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::count())->toBe(1);
});

test('start and pause reach the log and change nothing', function () {
    Http::fake(providerDictionaryFakes());

    $endpoint = genericEndpoint();
    $movie = ['kind' => 'movie', 'ids' => ['tmdb' => 603]];

    postGeneric($endpoint, genericPayload('start', $movie))->assertStatus(Response::HTTP_ACCEPTED);

    expect(lastGenericDelivery()->message)->toBe('kind_not_actionable');

    postGeneric($endpoint, genericPayload('pause', $movie, ['progress' => 30]))->assertStatus(Response::HTTP_ACCEPTED);

    expect(lastGenericDelivery()->message)->toBe('below_threshold')
        ->and(Play::count())->toBe(0);
});

test('the user field feeds the account filter', function () {
    Http::fake(providerDictionaryFakes());

    $user = User::factory()->create();
    $endpoint = genericEndpoint($user);
    $endpoint->update(['settings' => ['server_username' => 'Alex']]);
    $movie = ['kind' => 'movie', 'ids' => ['tmdb' => 603]];

    postGeneric($endpoint, genericPayload('complete', $movie, ['user' => 'anna']))->assertNoContent();

    expect(lastGenericDelivery()->message)->toBe('user_mismatch')
        ->and(Play::count())->toBe(0);

    postGeneric($endpoint, genericPayload('complete', $movie, ['user' => 'alex']))->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::where('user_id', $user->id)->count())->toBe(1);
});

test('a payload it cannot use is refused with the reason, and logged', function (array $payload, string $field) {
    postGeneric(genericEndpoint(), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(lastGenericDelivery()->status)->toBe(WebhookDeliveryStatus::Error)
        ->and(lastGenericDelivery()->message)->toBe('invalid_payload')
        ->and(lastGenericDelivery()->payload)->toBe($payload)
        ->and(Play::count())->toBe(0);
})->with([
    'no event' => [['media' => ['kind' => 'movie', 'ids' => ['tmdb' => 603]]], 'event'],
    'an event we do not know' => [genericPayload('added', ['kind' => 'movie', 'ids' => ['tmdb' => 603]]), 'event'],
    'a title without ids' => [genericPayload('complete', ['kind' => 'movie', 'title' => 'The Matrix', 'year' => 1999]), 'media.ids'],
    'an id that does not name this kind' => [genericPayload('complete', ['kind' => 'movie', 'ids' => ['isbn' => '9780316769488']]), 'media.ids'],
    'a kind we do not track' => [genericPayload('complete', ['kind' => 'podcast', 'ids' => ['tmdb' => 603]]), 'media.kind'],
    'an episode without its number' => [genericPayload('complete', ['kind' => 'episode', 'ids' => ['tmdb' => 1399], 'season' => 1]), 'media.episode'],
    'a progress report without how far' => [genericPayload('progress', ['kind' => 'book', 'ids' => ['isbn' => '9780316769488']]), 'amount'],
    'a rating without the rating' => [genericPayload('rate', ['kind' => 'movie', 'ids' => ['tmdb' => 603]]), 'rating'],
    'a rating off the scale' => [genericPayload('rate', ['kind' => 'movie', 'ids' => ['tmdb' => 603]], ['rating' => 11]), 'rating'],
    'a percentage over a hundred' => [genericPayload('stop', ['kind' => 'movie', 'ids' => ['tmdb' => 603]], ['progress' => 140]), 'progress'],
    'a viewing next week' => [genericPayload('complete', ['kind' => 'movie', 'ids' => ['tmdb' => 603]], ['occurred_at' => now()->addWeek()->toIso8601String()]), 'occurred_at'],
    'a version from the future' => [genericPayload('complete', ['kind' => 'movie', 'ids' => ['tmdb' => 603]], ['version' => 2]), 'version'],
]);

test('the reason names the ids a kind does take', function () {
    postGeneric(genericEndpoint(), genericPayload('complete', ['kind' => 'movie', 'ids' => ['isbn' => '9780316769488']]))
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['media.ids' => ['A movie is named by tmdb, imdb, not by isbn.']]);
});

test('JSON posted as plain text is still read', function () {
    Http::fake(providerDictionaryFakes());

    $endpoint = genericEndpoint();

    test()->call(
        'POST',
        "/webhooks/generic/{$endpoint->token}",
        server: ['CONTENT_TYPE' => 'text/plain'],
        content: (string) json_encode(genericPayload('complete', ['kind' => 'movie', 'ids' => ['tmdb' => 603]])),
    )->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::count())->toBe(1);
});

test('a wrong token is a 404', function () {
    genericEndpoint();

    test()->postJson('/webhooks/generic/nope', genericPayload('complete', ['kind' => 'movie', 'ids' => ['tmdb' => 603]]))
        ->assertNotFound();
});

test('the instructions name the ids, the events and the threshold the endpoint really checks', function () {
    config(['tracker.scrobble.completion_threshold' => 90]);
    enableGamesModule();
    enableBooksModule();
    enableMangaModule();
    enableComicsModule();

    $steps = collect(app(GenericScrobbleSource::class)->instructions());
    $text = $steps->map(fn ($step) => $step->body.' '.$step->code)->implode("\n");

    foreach (GenericScrobbleSource::IDS as $kind => $ids) {
        expect($text)->toContain(str_pad($kind, 10).implode(', ', $ids));
    }

    expect($text)->toContain('complete, progress, stop, start, pause, rate', '90%')
        ->and($text)->not->toContain('added');
});

test('a kind whose module this instance does not run is neither offered nor taken', function () {
    $text = collect(app(GenericScrobbleSource::class)->instructions())->map(fn ($step) => $step->body.' '.$step->code)->implode("\n");

    expect($text)->not->toContain(str_pad('comic', 10).'comicvine')
        ->and($text)->toContain(str_pad('movie', 10).'tmdb');
});

test('a report of a kind whose module is off is refused, not accepted to never match', function () {
    postGeneric(genericEndpoint(), genericPayload('complete', ['kind' => 'comic', 'ids' => ['comicvine' => '4050-4207']]))
        ->assertUnprocessable();
});
