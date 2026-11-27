<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Scrobble\SettleDelivery;
use App\Catalog\MediaKind;
use App\Enums\PlayOutcome;
use App\Enums\WebhookDeliveryStatus;
use App\Integrations\Scrobble\Data\ScrobbleEvent;
use App\Integrations\Scrobble\ScrobbleEventKind;
use App\Jobs\ProcessScrobbleEvent;
use App\Library\Events\PlayRecorded;
use App\Library\ScrobbleDedup;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\ExternalServiceException;
use App\Tasks\Maintenance\Checks\ExpiredDataCleanup;
use App\Tasks\Maintenance\MaintenanceChecks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The fake source's payload is already a normalized event, so these
 * exercise the framework itself: routing, logging, deduplication, the
 * threshold, and the actions the pipeline ends in.
 */
function postScrobble(WebhookEndpoint $endpoint, array $payload): TestResponse
{
    return test()->postJson("/webhooks/{$endpoint->source}/{$endpoint->token}", $payload);
}

function completedMovie(array $payload = []): array
{
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie'))]);

    $user = User::factory()->create();
    $endpoint = fakeScrobbleEndpoint($user);

    return [$user, $endpoint, [
        'kind' => 'complete',
        'media_kind' => 'movie',
        'ids' => ['tmdb' => '603'],
        'title' => 'The Matrix',
        'year' => 1999,
        ...$payload,
    ]];
}

test('a completed movie becomes a play, announced as a scrobble', function () {
    Http::fake(providerDictionaryFakes());

    Event::fake([PlayRecorded::class]);

    [$user, $endpoint, $payload] = completedMovie();

    postScrobble($endpoint, $payload)->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::where('user_id', $user->id)->count())->toBe(1);

    Event::assertDispatched(PlayRecorded::class, fn (PlayRecorded $e): bool => $e->source->value() === 'scrobble:fake');

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Accepted)
        ->and($delivery->kind)->toBe('complete')
        ->and($delivery->media_label)->toBe('The Matrix (1999)')
        ->and($endpoint->refresh()->last_received_at)->not->toBeNull();
});

test('a stop past the threshold counts as watched, below it does not', function () {
    Http::fake(providerDictionaryFakes());

    [$user, $endpoint, $payload] = completedMovie(['kind' => 'stop', 'percent' => 92]);

    postScrobble($endpoint, $payload)->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::where('user_id', $user->id)->count())->toBe(1);

    postScrobble($endpoint, [...$payload, 'percent' => 40, 'title' => 'Half-watched'])
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::where('user_id', $user->id)->count())->toBe(1)
        ->and(WebhookDelivery::query()->latest('id')->firstOrFail()->message)->toBe('below_threshold');
});

test('the same viewing reported by a second endpoint is not logged twice', function () {
    Http::fake(providerDictionaryFakes());

    [$user, $endpoint, $payload] = completedMovie(['occurred_at' => '2026-07-01 21:00:00']);
    $other = fakeScrobbleEndpoint($user, ['label' => 'Second server']);

    postScrobble($endpoint, $payload)->assertStatus(Response::HTTP_ACCEPTED);
    postScrobble($other, $payload)->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::where('user_id', $user->id)->count())->toBe(1)
        ->and(WebhookDelivery::query()->latest('id')->firstOrFail()->message)->toBe('already_played');
});

test('a viewing reported in UTC lands on the instance\'s clock and is weighed against plays on it', function () {
    instanceTimezone('Europe/Warsaw');
    Http::fake(providerDictionaryFakes());

    [$user, $endpoint, $payload] = completedMovie();
    $movie = MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-07-01 21:00:00')));

    // Half an hour past the window on the instance's clock, but inside it
    // if the report's UTC hour were taken for the instance's own.
    $reported = CarbonImmutable::parse('2026-07-01 21:00:00')->addHours(ScrobbleDedup::windowHours())->addMinutes(30);

    postScrobble($endpoint, [...$payload, 'occurred_at' => $reported->utc()->toIso8601ZuluString()])->assertStatus(Response::HTTP_ACCEPTED);

    expect(DB::table('plays')->where('user_id', $user->id)->orderBy('id')->pluck('watched_at')->all())
        ->toBe(['2026-07-01 21:00:00', $reported->toDateTimeString()]);
});

test('an identical retransmission to the same endpoint is one delivery', function () {
    Http::fake(providerDictionaryFakes());

    [$user, $endpoint, $payload] = completedMovie();

    postScrobble($endpoint, $payload)->assertStatus(Response::HTTP_ACCEPTED);
    postScrobble($endpoint, $payload)->assertNoContent();

    expect(WebhookDelivery::query()->latest('id')->firstOrFail()->message)->toBe('duplicate_delivery')
        ->and(Play::where('user_id', $user->id)->count())->toBe(1);
});

test('a partial stop on a book records the page it reached', function () {
    enableBooksModule();
    // The ISBN lands on the book; persisting it then reads its details.
    fakeHardcover(['ByIsbn' => 'by_isbn', 'Details' => 'book']);

    $user = User::factory()->create();
    $endpoint = fakeScrobbleEndpoint($user);

    postScrobble($endpoint, [
        'kind' => 'stop',
        'media_kind' => 'book',
        'ids' => ['isbn' => '9780261103344'],
        'percent' => 50,
        'title' => 'The Hobbit',
    ]);

    // 320 pages in the fixture, so halfway is page 160.
    expect(MediaUserState::where('user_id', $user->id)->value('progress'))->toBe(160)
        ->and(WebhookDelivery::query()->firstOrFail()->status)->toBe(WebhookDeliveryStatus::Accepted);
});

test('a rating event sets the rating', function () {
    Http::fake(providerDictionaryFakes());

    [$user, $endpoint, $payload] = completedMovie(['kind' => 'rate', 'rating' => 9]);

    postScrobble($endpoint, $payload)->assertStatus(Response::HTTP_ACCEPTED);

    expect(Rating::where('user_id', $user->id)->value('rating'))->toBe(9)
        ->and(Play::where('user_id', $user->id)->count())->toBe(0);
});

test('an episode resolves through its show and season numbers', function () {
    Http::fake([
        '*/tv/1399/season/1/episode/1*' => Http::response(jsonFixture('tmdb/episode')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399*' => tmdbShowAnswer(seasons: [1 => jsonFixture('tmdb/season')]),
        ...providerDictionaryFakes(),
    ]);

    $user = User::factory()->create();
    $endpoint = fakeScrobbleEndpoint($user);

    postScrobble($endpoint, [
        'kind' => 'complete',
        'media_kind' => 'episode',
        // The show's id, said to be the show's: the episode's own ids are a
        // different number entirely.
        'series_ids' => ['tmdb' => '1399'],
        'season' => 1,
        'episode' => 1,
        'title' => 'Winter Is Coming',
    ])->assertStatus(Response::HTTP_ACCEPTED);

    $play = Play::query()->with('mediaItem')->firstOrFail();

    expect($play->mediaItem->type)->toBe('episode')
        ->and($play->mediaItem->provider_ref)->toBe('1399:1:1')
        ->and($play->outcome)->toBe(PlayOutcome::Completed);
});

test("an episode's own id places the episode, never a show that shares the number", function () {
    Http::fake([
        // tt1480055 is an episode of Game of Thrones; find/ says so by
        // answering in the episode bucket and nowhere else.
        '*/find/tt1480055*' => Http::response([
            'movie_results' => [],
            'tv_results' => [],
            'tv_episode_results' => [[
                'id' => 63056,
                'show_id' => 1399,
                'season_number' => 1,
                'episode_number' => 1,
            ]],
        ]),
        '*/tv/1399/season/1/episode/1*' => Http::response(jsonFixture('tmdb/episode')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399*' => tmdbShowAnswer(seasons: [1 => jsonFixture('tmdb/season')]),
        ...providerDictionaryFakes(),
    ]);

    $user = User::factory()->create();
    $endpoint = fakeScrobbleEndpoint($user);

    postScrobble($endpoint, [
        'kind' => 'complete',
        'media_kind' => 'episode',
        'ids' => ['imdb' => 'tt1480055'],
        'season' => 1,
        'episode' => 1,
        'title' => 'Winter Is Coming',
    ])->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::query()->with('mediaItem')->firstOrFail()->mediaItem->provider_ref)->toBe('1399:1:1');
});

test("an episode's bare tmdb number is never read as a show", function () {
    $user = User::factory()->create();
    $endpoint = fakeScrobbleEndpoint($user);

    // 63056 is the TMDB id of an episode. Read as a show it would be a
    // different series altogether, so it resolves to nothing at all.
    postScrobble($endpoint, [
        'kind' => 'complete',
        'media_kind' => 'episode',
        'ids' => ['tmdb' => '63056'],
        'season' => 1,
        'episode' => 1,
    ])->assertStatus(Response::HTTP_ACCEPTED);

    expect(Play::count())->toBe(0)
        ->and(WebhookDelivery::query()->firstOrFail()->status)->toBe(WebhookDeliveryStatus::Unmatched);
});

test('an id nobody can place is logged as unmatched', function () {
    $user = User::factory()->create();
    $endpoint = fakeScrobbleEndpoint($user);

    postScrobble($endpoint, [
        'kind' => 'complete',
        'media_kind' => 'movie',
        'ids' => ['nonsense' => 'xyz'],
        'title' => 'Who knows',
    ])->assertStatus(Response::HTTP_ACCEPTED);

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Unmatched)
        ->and($delivery->message)->toBe('no_external_id_match')
        ->and(Play::count())->toBe(0);
});

test('a payload that is not an event is answered quietly', function () {
    $endpoint = fakeScrobbleEndpoint(User::factory()->create());

    postScrobble($endpoint, ['kind' => 'noise'])->assertNoContent();

    expect(WebhookDelivery::query()->firstOrFail()->message)->toBe('not_an_event');
});

test('a broken payload is logged and still answered 204, never 5xx', function () {
    $endpoint = fakeScrobbleEndpoint(User::factory()->create());

    postScrobble($endpoint, ['kind' => 'broken'])->assertNoContent();

    expect(WebhookDelivery::query()->firstOrFail()->status)->toBe(WebhookDeliveryStatus::Error);
});

test('an oversized payload is dropped without a crash', function () {
    $endpoint = fakeScrobbleEndpoint(User::factory()->create());

    postScrobble($endpoint, [
        'kind' => 'complete',
        'media_kind' => 'movie',
        'ids' => ['tmdb' => '603'],
        'padding' => str_repeat('x', 300 * 1024),
    ])->assertNoContent();

    expect(WebhookDelivery::query()->firstOrFail()->message)->toBe('payload_too_large');
});

test('a wrong token, a wrong source and a disabled endpoint are all 404', function () {
    $user = User::factory()->create();
    $endpoint = fakeScrobbleEndpoint($user);

    $this->postJson("/webhooks/fake/{$endpoint->token}x", ['kind' => 'noise'])->assertNotFound();
    $this->postJson("/webhooks/plex/{$endpoint->token}", ['kind' => 'noise'])->assertNotFound();

    $endpoint->update(['enabled' => false]);

    postScrobble($endpoint, ['kind' => 'noise'])->assertNotFound();
});

test('a blocked account stops receiving scrobbles', function () {
    $user = User::factory()->create(['blocked_at' => now()]);
    $endpoint = fakeScrobbleEndpoint($user);

    postScrobble($endpoint, ['kind' => 'noise'])->assertNotFound();
});

test('the ingest route carries no session and needs no csrf token', function () {
    $endpoint = fakeScrobbleEndpoint(User::factory()->create());

    $response = postScrobble($endpoint, ['kind' => 'noise']);

    $response->assertNoContent();

    // No session cookie means no session was started: the route never
    // entered the web middleware group.
    expect($response->headers->getCookies())->toBe([]);
});

test('the ingest route is throttled per token', function () {
    $this->freezeTime();

    $endpoint = fakeScrobbleEndpoint(User::factory()->create());

    foreach (range(1, config()->integer('tracker.rate_limits.webhooks_per_endpoint')) as $ignored) {
        postScrobble($endpoint, ['kind' => 'noise'])->assertNoContent();
    }

    postScrobble($endpoint, ['kind' => 'noise'])->assertStatus(429);
});

test('deliveries are pruned by age and by count', function () {
    $endpoint = fakeScrobbleEndpoint(User::factory()->create());

    WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'status' => WebhookDeliveryStatus::Accepted,
        'created_at' => CarbonImmutable::now()->subDays(60),
        'updated_at' => CarbonImmutable::now()->subDays(60),
    ]);

    $kept = config()->integer('tracker.retention.webhook_deliveries_per_endpoint');

    foreach (range(1, $kept + 5) as $ignored) {
        WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->id,
            'status' => WebhookDeliveryStatus::Accepted,
        ]);
    }

    app(MaintenanceChecks::class)->perform('run-of-alex', [ExpiredDataCleanup::CODE]);

    expect(WebhookDelivery::query()->count())->toBe($kept);
});

test('an endpoint mints its own token', function () {
    $endpoint = fakeScrobbleEndpoint(User::factory()->create());

    expect($endpoint->token)->toHaveLength(48)
        ->and($endpoint->toArray())->not->toHaveKey('token');
});

test('url tokens are stored encrypted and found by their hash', function () {
    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, 'fake');
    $calendar = $user->calendarToken();

    $endpointRow = DB::table('webhook_endpoints')->where('id', $endpoint->id)->first();
    $userRow = DB::table('users')->where('id', $user->id)->first();

    expect($endpointRow->token)->not->toBe($endpoint->token)
        ->and($endpointRow->token_hash)->toBe(hash('sha256', $endpoint->token))
        ->and($userRow->calendar_token)->not->toBe($calendar)
        ->and($userRow->calendar_token_hash)->toBe(hash('sha256', $calendar))
        // The settings page still shows the address it can rebuild.
        ->and($endpoint->fresh()->token)->toBe($endpoint->token);

    $this->get("/calendar/{$calendar}.ics")->assertOk();
});

test('a delivery read as an event or as routine traffic keeps no payload, one naming no event keeps it', function () {
    Http::fake(providerDictionaryFakes());
    [, $endpoint, $payload] = completedMovie();

    postScrobble($endpoint, [...$payload, 'account' => 'Grandma'])->assertStatus(Response::HTTP_ACCEPTED);
    postScrobble($endpoint, ['kind' => 'noise', 'account' => 'Grandma'])->assertNoContent();
    postScrobble($endpoint, ['something' => 'else'])->assertNoContent();

    $deliveries = WebhookDelivery::query()->orderBy('id')->get();

    expect($deliveries[0]->payload)->toBeNull()
        ->and($deliveries[1]->message)->toBe('not_an_event')
        ->and($deliveries[1]->payload)->toBeNull()
        ->and($deliveries[2]->message)->toBe('not_an_event')
        ->and($deliveries[2]->payload)->toBe(['something' => 'else']);
});

test('when a server was last heard from is written once a minute, not on every request', function () {
    [, $endpoint] = completedMovie();

    postScrobble($endpoint, ['not' => 'an event']);
    $first = $endpoint->refresh()->last_received_at;

    $this->travel(30)->seconds();
    postScrobble($endpoint, ['not' => 'an event']);
    expect($endpoint->refresh()->last_received_at?->equalTo($first))->toBeTrue();

    $this->travel(31)->seconds();
    postScrobble($endpoint, ['not' => 'an event']);
    expect($endpoint->refresh()->last_received_at?->gt($first))->toBeTrue();
});

test('a delivery that could not be saved says so on the card, without the exception', function () {
    Http::fake(['*' => Http::response(null, 500)]);
    $endpoint = fakeScrobbleEndpoint(User::factory()->create());
    $delivery = $endpoint->deliveries()->create(['status' => WebhookDeliveryStatus::Received]);
    $event = new ScrobbleEvent(ScrobbleEventKind::Complete, MediaKind::Movie, ['tmdb' => '603']);

    $job = new ProcessScrobbleEvent($delivery->id, $event);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(ExternalServiceException::class);

    expect($endpoint->refresh()->last_error)->toBe(SettleDelivery::SAVE_FAILED)
        ->and($delivery->refresh()->message)->toBe('processing_failed');
});

test('a foreign id that could not be looked up is retried, not settled as unmatched', function () {
    Http::fake(['*' => Http::response(null, 503)]);
    $endpoint = fakeScrobbleEndpoint(User::factory()->create());
    $delivery = $endpoint->deliveries()->create(['status' => WebhookDeliveryStatus::Received]);
    $event = new ScrobbleEvent(ScrobbleEventKind::Complete, MediaKind::Episode, ['tvdb' => '349232']);

    $job = new ProcessScrobbleEvent($delivery->id, $event);

    // A media server never sends the event twice: an outage must reach the
    // worker, which tries again later.
    expect(fn () => app()->call([$job, 'handle']))->toThrow(ExternalServiceException::class);

    expect($delivery->refresh()->status)->not->toBe(WebhookDeliveryStatus::Unmatched);
});
