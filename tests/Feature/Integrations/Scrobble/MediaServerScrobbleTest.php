<?php

use App\Enums\WebhookDeliveryStatus;
use App\Http\Controllers\WebhookIngestController;
use App\Integrations\Emby\EmbyScrobbleSource;
use App\Integrations\Jellyfin\JellyfinScrobbleSource;
use App\Integrations\Plex\PlexScrobbleSource;
use App\Integrations\Tautulli\TautulliScrobbleSource;
use App\Models\Play;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The media servers (and Tautulli, watching Plex), each parsed from a payload in the shape it
 * actually posts. The framework itself is covered by ScrobbleIngestTest;
 * what is at stake here is whether a real payload becomes the right title.
 */

/** TMDB answers for the two titles every fixture is about. */
function fakeTmdbForScrobbles(): void
{
    Http::fake([
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        '*/tv/1399/season/1/episode/1*' => Http::response(jsonFixture('tmdb/episode')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399*' => tmdbShowAnswer(seasons: [1 => jsonFixture('tmdb/season')]),
        // An episode's own ids answer in the episode bucket, a show's in
        // the show bucket — which is the whole difference between them.
        '*/find/tt1480055*' => Http::response(findFixture('tv_episode_results', [
            'id' => 63056, 'show_id' => 1399, 'season_number' => 1, 'episode_number' => 1,
        ])),
        '*/find/3254641*' => Http::response(findFixture('tv_episode_results', [
            'id' => 63056, 'show_id' => 1399, 'season_number' => 1, 'episode_number' => 1,
        ])),
        '*/find/121361*' => Http::response(findFixture('tv_results', ['id' => 1399])),
    ]);
}

/**
 * @param  array<string, mixed>  $result
 * @return array<string, mixed>
 */
function findFixture(string $bucket, array $result): array
{
    return [
        'movie_results' => [],
        'person_results' => [],
        'tv_results' => [],
        'tv_episode_results' => [],
        'tv_season_results' => [],
        $bucket => [$result],
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function postToServerEndpoint(WebhookEndpoint $endpoint, array $payload): TestResponse
{
    return test()->postJson("/webhooks/{$endpoint->source}/{$endpoint->token}", $payload);
}

function lastDelivery(): WebhookDelivery
{
    return WebhookDelivery::query()->latest('id')->firstOrFail();
}

test('a film watched to the end on Jellyfin is a play', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, JellyfinScrobbleSource::CODE);

    postToServerEndpoint($endpoint, jsonFixture('scrobble/jellyfin/movie-stop-completed'))
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::where('user_id', $user->id)->count())->toBe(1)
        ->and(lastDelivery()->status)->toBe(WebhookDeliveryStatus::Accepted)
        ->and(lastDelivery()->media_label)->toBe('The Matrix (1999)');
});

test('a film left halfway on Jellyfin changes nothing', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $endpoint = scrobbleEndpoint(User::factory()->create(), JellyfinScrobbleSource::CODE);

    postToServerEndpoint($endpoint, jsonFixture('scrobble/jellyfin/movie-stop-partial'))
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::count())->toBe(0)
        ->and(lastDelivery()->message)->toBe('below_threshold');
});

test('a Jellyfin episode is placed through the show id its payload carries', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, JellyfinScrobbleSource::CODE);

    postToServerEndpoint($endpoint, jsonFixture('scrobble/jellyfin/episode-stop-completed'))
        ->assertStatus(Response::HTTP_ACCEPTED);

    $play = Play::query()->with('mediaItem')->firstOrFail();

    expect($play->mediaItem->provider_ref)->toBe('1399:1:1')
        ->and(lastDelivery()->media_label)->toBe('Game of Thrones S01E01 (2011)');
});

test('a Jellyfin episode without show ids still places itself by its own', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $payload = jsonFixture('scrobble/jellyfin/episode-stop-completed');
    // Plugin versions before show-level ids existed sent only these.
    $payload['series_provider_ids'] = ['tmdb' => '', 'imdb' => '', 'tvdb' => ''];

    $endpoint = scrobbleEndpoint(User::factory()->create(), JellyfinScrobbleSource::CODE);

    postToServerEndpoint($endpoint, $payload)->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::query()->with('mediaItem')->firstOrFail()->mediaItem->provider_ref)->toBe('1399:1:1');
});

test('Jellyfin progress pings are not events, and added items only reach the log', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $endpoint = scrobbleEndpoint(User::factory()->create(), JellyfinScrobbleSource::CODE);

    postToServerEndpoint($endpoint, jsonFixture('scrobble/jellyfin/progress-ping'))->assertNoContent();

    // The server's routine traffic names the household: only the fact stays.
    expect(lastDelivery()->message)->toBe('not_an_event')
        ->and(lastDelivery()->payload)->toBeNull();

    postToServerEndpoint($endpoint, jsonFixture('scrobble/jellyfin/item-added'))
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(lastDelivery()->kind)->toBe('added')
        ->and(lastDelivery()->message)->toBe('kind_not_actionable')
        ->and(Play::count())->toBe(0);
});

test('a Jellyfin episode placed by a nested Series object', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $payload = jsonFixture('scrobble/jellyfin/episode-stop-completed');
    // The shape other trackers read: show ids under a Series object rather
    // than beside the episode's own.
    $payload['series_provider_ids'] = ['tmdb' => '', 'imdb' => '', 'tvdb' => ''];
    $payload['provider_ids'] = ['tmdb' => '', 'imdb' => '', 'tvdb' => ''];
    $payload['Series'] = ['ProviderIds' => ['Tmdb' => '1399']];

    $endpoint = scrobbleEndpoint(User::factory()->create(), JellyfinScrobbleSource::CODE);

    postToServerEndpoint($endpoint, $payload)->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::query()->with('mediaItem')->firstOrFail()->mediaItem->provider_ref)->toBe('1399:1:1');
});

test("the item's own played flag never stands in for this viewing", function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $payload = jsonFixture('scrobble/jellyfin/movie-stop-partial');
    // Watched before, so the library says "played" — while this viewing
    // stopped at 40%. Reading that as completion would log a play every
    // time somebody reruns two minutes of a favourite.
    $payload['UserData'] = ['Played' => true];

    $endpoint = scrobbleEndpoint(User::factory()->create(), JellyfinScrobbleSource::CODE);

    postToServerEndpoint($endpoint, $payload)->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::count())->toBe(0)
        ->and(lastDelivery()->message)->toBe('below_threshold');
});

test('a payload the plugin labelled as plain text is still read', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, JellyfinScrobbleSource::CODE);

    // The Webhook plugin's own default: JSON in the body, "text/plain" on
    // the envelope. Nothing parses that for us.
    $this->call(
        'POST',
        "/webhooks/jellyfin/{$endpoint->token}",
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'text/plain'],
        (string) json_encode(jsonFixture('scrobble/jellyfin/movie-stop-completed')),
    )->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::where('user_id', $user->id)->count())->toBe(1);
});

test('Jellyfin sending all its properties instead of the template still works', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, JellyfinScrobbleSource::CODE);

    postToServerEndpoint($endpoint, jsonFixture('scrobble/jellyfin/movie-send-all-properties'))
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::where('user_id', $user->id)->count())->toBe(1);
});

test('an endpoint bound to one account ignores the rest of the household', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $endpoint = scrobbleEndpoint(User::factory()->create(), JellyfinScrobbleSource::CODE, [
        'settings' => ['server_username' => 'Kasia'],
    ]);

    postToServerEndpoint($endpoint, jsonFixture('scrobble/jellyfin/movie-stop-completed'))->assertNoContent();

    expect(Play::count())->toBe(0)
        ->and(lastDelivery()->message)->toBe('user_mismatch');

    // The same account under a different capitalization is the same person.
    $endpoint->update(['settings' => ['server_username' => '  ALEX ']]);

    postToServerEndpoint($endpoint, jsonFixture('scrobble/jellyfin/movie-stop-completed'))
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::count())->toBe(1);
});

test('a film watched to the end on Emby is a play', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, EmbyScrobbleSource::CODE);

    postToServerEndpoint($endpoint, jsonFixture('scrobble/emby/movie-stop-completed'))
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::where('user_id', $user->id)->count())->toBe(1)
        ->and(lastDelivery()->media_label)->toBe('The Matrix (1999)');
});

test('an Emby episode is placed by its own tvdb id', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $endpoint = scrobbleEndpoint(User::factory()->create(), EmbyScrobbleSource::CODE);

    postToServerEndpoint($endpoint, jsonFixture('scrobble/emby/episode-stop-completed'))
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::query()->with('mediaItem')->firstOrFail()->mediaItem->provider_ref)->toBe('1399:1:1');
});

test("Emby's own test button is not a viewing", function () {
    $endpoint = scrobbleEndpoint(User::factory()->create(), EmbyScrobbleSource::CODE);

    postToServerEndpoint($endpoint, jsonFixture('scrobble/emby/webhook-test'))->assertNoContent();

    expect(lastDelivery()->message)->toBe('not_an_event');
});

test('a Plex scrobble arrives as a form field and becomes a play', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, PlexScrobbleSource::CODE);

    // Plex posts multipart/form-data: the JSON is one field, and a
    // thumbnail we ignore is another.
    $this->post("/webhooks/plex/{$endpoint->token}", [
        'payload' => json_encode(jsonFixture('scrobble/plex/movie-scrobble')),
    ])->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::where('user_id', $user->id)->count())->toBe(1)
        ->and(lastDelivery()->media_label)->toBe('The Matrix (1999)');
});

test('a Plex episode is placed by its own id, not by the number in it', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $endpoint = scrobbleEndpoint(User::factory()->create(), PlexScrobbleSource::CODE);

    $this->post("/webhooks/plex/{$endpoint->token}", [
        'payload' => json_encode(jsonFixture('scrobble/plex/episode-scrobble')),
    ])->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::query()->with('mediaItem')->firstOrFail()->mediaItem->provider_ref)->toBe('1399:1:1');
});

test('a Plex library on the legacy agent places its episode through the show', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $endpoint = scrobbleEndpoint(User::factory()->create(), PlexScrobbleSource::CODE);

    $this->post("/webhooks/plex/{$endpoint->token}", [
        'payload' => json_encode(jsonFixture('scrobble/plex/episode-scrobble-legacy-agent')),
    ])->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::query()->with('mediaItem')->firstOrFail()->mediaItem->provider_ref)->toBe('1399:1:1');
});

test('pausing and rating on Plex reach the log and stop there', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $user = User::factory()->create();
    $paused = scrobbleEndpoint($user, PlexScrobbleSource::CODE);
    $rated = scrobbleEndpoint($user, PlexScrobbleSource::CODE, ['label' => 'Second player']);

    $this->post("/webhooks/plex/{$paused->token}", [
        'payload' => json_encode(jsonFixture('scrobble/plex/movie-pause')),
    ])->assertStatus(Response::HTTP_ACCEPTED);

    // A film counts no progress of its own, so a pause is simply not far
    // enough in to be anything.
    expect(lastDelivery()->message)->toBe('below_threshold');

    $this->post("/webhooks/plex/{$rated->token}", [
        'payload' => json_encode(jsonFixture('scrobble/plex/movie-rate')),
    ])->assertStatus(Response::HTTP_ACCEPTED);

    expect(lastDelivery()->message)->toBe('rating_not_imported')
        ->and(Play::count())->toBe(0);
});

test('two different Plex events to one endpoint are two deliveries, a resent one is not', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, PlexScrobbleSource::CODE);
    $scrobble = ['payload' => json_encode(jsonFixture('scrobble/plex/movie-scrobble'))];

    // Multipart: the request has no raw body, only fields — what tells two
    // deliveries apart is the field Plex put the event in.
    $this->post("/webhooks/plex/{$endpoint->token}", [
        'payload' => json_encode(jsonFixture('scrobble/plex/movie-pause')),
    ])->assertStatus(Response::HTTP_ACCEPTED);
    $this->post("/webhooks/plex/{$endpoint->token}", $scrobble)->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::where('user_id', $user->id)->count())->toBe(1);

    $this->post("/webhooks/plex/{$endpoint->token}", $scrobble)->assertNoContent();

    expect(lastDelivery()->message)->toBe('duplicate_delivery')
        ->and(Play::where('user_id', $user->id)->count())->toBe(1);
});

test('a Plex payload field over the size limit is set aside', function () {
    $endpoint = scrobbleEndpoint(User::factory()->create(), PlexScrobbleSource::CODE);

    $this->post("/webhooks/plex/{$endpoint->token}", [
        'payload' => str_repeat('x', WebhookIngestController::MAX_PAYLOAD_BYTES + 1),
    ])->assertNoContent();

    expect(lastDelivery()->message)->toBe('payload_too_large');
});

test('a film Tautulli calls watched is a play, dated when Tautulli saw it', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, TautulliScrobbleSource::CODE);

    postToServerEndpoint($endpoint, jsonFixture('scrobble/tautulli/movie-watched'))
        ->assertStatus(Response::HTTP_ACCEPTED);

    $play = Play::query()->where('user_id', $user->id)->sole();

    expect($play->watched_at->toIso8601String())->toBe('2026-09-21T21:00:00+00:00')
        ->and(lastDelivery()->status)->toBe(WebhookDeliveryStatus::Accepted)
        ->and(lastDelivery()->media_label)->toBe('The Matrix (1999)');
});

test("a Tautulli episode is placed through the show's ids and its numbers", function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $endpoint = scrobbleEndpoint(User::factory()->create(), TautulliScrobbleSource::CODE);

    postToServerEndpoint($endpoint, jsonFixture('scrobble/tautulli/episode-watched'))
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::query()->with('mediaItem')->firstOrFail()->mediaItem->provider_ref)->toBe('1399:1:1')
        ->and(lastDelivery()->media_label)->toBe('Game of Thrones S01E01 (2011)');
});

test('a Tautulli episode with only the show tvdb id is not taken for the episode itself', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $endpoint = scrobbleEndpoint(User::factory()->create(), TautulliScrobbleSource::CODE);

    // TMDB answers 121361 in the show bucket. Read as the episode's own id,
    // it would find no episode and the viewing would be lost.
    postToServerEndpoint($endpoint, jsonFixture('scrobble/tautulli/episode-watched-legacy-agent'))
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::query()->with('mediaItem')->firstOrFail()->mediaItem->provider_ref)->toBe('1399:1:1');
});

test('a Tautulli stop decides by how far it got', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, TautulliScrobbleSource::CODE);

    postToServerEndpoint($endpoint, jsonFixture('scrobble/tautulli/movie-stop-partial'))
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::count())->toBe(0)
        ->and(lastDelivery()->message)->toBe('below_threshold');

    $payload = jsonFixture('scrobble/tautulli/movie-stop-partial');
    $payload['progress'] = '96';

    postToServerEndpoint($endpoint, $payload)->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::count())->toBe(1);

    // Both triggers wired to the same address: the Watched that follows the
    // stop is the same viewing.
    postToServerEndpoint($endpoint, jsonFixture('scrobble/tautulli/movie-watched'))
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::count())->toBe(1)
        ->and(lastDelivery()->message)->toBe('already_played');
});

test('Tautulli recently added, music and its own Test button change nothing', function () {
    Http::fake(providerDictionaryFakes());

    fakeTmdbForScrobbles();

    $endpoint = scrobbleEndpoint(User::factory()->create(), TautulliScrobbleSource::CODE);

    postToServerEndpoint($endpoint, jsonFixture('scrobble/tautulli/recently-added'))
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(lastDelivery()->kind)->toBe('added')
        ->and(lastDelivery()->message)->toBe('kind_not_actionable');

    postToServerEndpoint($endpoint, jsonFixture('scrobble/tautulli/track-watched'))->assertNoContent();

    expect(lastDelivery()->message)->toBe('not_an_event');

    // The Test button posts the template with its placeholders intact; the
    // log keeps it, which is how the user sees the address works.
    postToServerEndpoint($endpoint, jsonFixture('scrobble/tautulli/test-button'))->assertNoContent();

    expect(lastDelivery()->message)->toBe('not_an_event')
        ->and(lastDelivery()->payload)->toMatchArray(['action' => '{action}'])
        ->and(Play::count())->toBe(0);
});

test('a Tautulli endpoint bound to one Plex account ignores the rest of the household', function () {
    fakeTmdbForScrobbles();

    $endpoint = scrobbleEndpoint(User::factory()->create(), TautulliScrobbleSource::CODE, [
        'settings' => ['server_username' => 'Kasia'],
    ]);

    postToServerEndpoint($endpoint, jsonFixture('scrobble/tautulli/movie-watched'))->assertNoContent();

    expect(Play::count())->toBe(0)
        ->and(lastDelivery()->message)->toBe('user_mismatch');
});
