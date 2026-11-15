<?php

use App\Enums\WebhookDeliveryStatus;
use App\Integrations\Jellyfin\JellyfinScrobbleSource;
use App\Integrations\Plex\PlexScrobbleSource;
use App\Integrations\Tautulli\TautulliScrobbleSource;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

test('the page offers every registered source, with nothing connected yet', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/integrations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Integrations')
            ->where('sources.0.code', 'jellyfin')
            ->where('sources.0.name', 'Jellyfin')
            ->where('sources.0.endpoints', [])
            ->where('sources.0.canTest', true)
            ->has('sources.0.instructions')
            // Plex webhooks cost money and the page has to say so.
            ->where('sources.1.code', 'plex')
            ->whereNot('sources.1.notice', null)
            // …and the free way round it sits right under it.
            ->where('sources.2.code', 'tautulli')
            ->where('sources.3.code', 'emby')
        );
});

test('connecting a server mints an address nobody could guess', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/integrations/jellyfin')
        ->assertRedirect();

    $endpoint = WebhookEndpoint::query()->firstOrFail();

    expect($endpoint->user_id)->toBe($user->id)
        ->and($endpoint->enabled)->toBeTrue()
        ->and($endpoint->token)->toHaveLength(48);

    $this->actingAs($user)
        ->get('/settings/integrations')
        ->assertInertia(fn (Assert $page) => $page
            ->where('sources.0.endpoints.0.url', route('webhooks.ingest', [
                'source' => 'jellyfin',
                'token' => $endpoint->token,
            ]))
            ->where('sources.0.endpoints.0.serverUsername', null)
        );
});

test('an address only this machine understands says so', function () {
    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, JellyfinScrobbleSource::CODE);

    config(['app.url' => 'http://localhost:8080']);
    URL::forceRootUrl('http://localhost:8080');

    $this->actingAs($user)
        ->get('/settings/integrations')
        ->assertInertia(fn (Assert $page) => $page->where('sources.0.endpoints.0.loopback', true));

    // An instance reachable by name or by LAN address needs no warning,
    // and gets none.
    URL::forceRootUrl('http://videtrack.home:8080');

    $this->actingAs($user)
        ->get('/settings/integrations')
        ->assertInertia(fn (Assert $page) => $page->where('sources.0.endpoints.0.loopback', false));

    expect($endpoint->refresh()->token)->toHaveLength(48);
});

test('one server takes up to ten addresses per account, and the page says so', function () {
    $user = User::factory()->create();

    foreach (range(1, 10) as $ignored) {
        $this->actingAs($user)->post('/settings/integrations/jellyfin')->assertRedirect()->assertSessionHasNoErrors();
    }

    $this->actingAs($user)->post('/settings/integrations/jellyfin')->assertSessionHasErrors('source');

    expect(WebhookEndpoint::query()->where('user_id', $user->id)->count())->toBe(10);

    // Another server, and another account, count on their own.
    $this->actingAs($user)->post('/settings/integrations/plex')->assertSessionHasNoErrors();
    $this->actingAs(User::factory()->create())->post('/settings/integrations/jellyfin')->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->get('/settings/integrations')
        ->assertInertia(fn (Assert $page) => $page->where('sources.0.endpointLimit', 10));
});

test('a source nobody registered cannot be connected', function () {
    $this->actingAs(User::factory()->create())
        ->post('/settings/integrations/kodi')
        ->assertNotFound();
});

test('the settings form saves the label, the account and the switch', function () {
    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, JellyfinScrobbleSource::CODE);

    $this->actingAs($user)
        ->patch("/settings/integrations/endpoints/{$endpoint->id}", [
            'label' => 'Living room',
            'enabled' => false,
            'server_username' => 'alex',
        ])
        ->assertRedirect();

    $endpoint->refresh();

    expect($endpoint->label)->toBe('Living room')
        ->and($endpoint->enabled)->toBeFalse()
        ->and($endpoint->serverUsername())->toBe('alex');

    // Clearing the account name means "whoever plays it" again.
    $this->actingAs($user)
        ->patch("/settings/integrations/endpoints/{$endpoint->id}", [
            'label' => '',
            'enabled' => true,
            'server_username' => '',
        ]);

    expect($endpoint->refresh()->serverUsername())->toBeNull();
});

test('a server username longer than the field takes is refused', function () {
    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, JellyfinScrobbleSource::CODE);

    $this->actingAs($user)
        ->patch("/settings/integrations/endpoints/{$endpoint->id}", [
            'enabled' => true,
            'server_username' => str_repeat('x', 101),
        ])
        ->assertSessionHasErrors('server_username');
});

test('rotating the token kills the address that leaked and keeps the log', function () {
    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, JellyfinScrobbleSource::CODE, ['settings' => ['server_username' => 'alex']]);
    $endpoint->deliveries()->create(['status' => WebhookDeliveryStatus::Accepted]);

    $old = $endpoint->token;

    $this->actingAs($user)
        ->post("/settings/integrations/endpoints/{$endpoint->id}/rotate")
        ->assertRedirect();

    $endpoint->refresh();

    expect($endpoint->token)->not->toBe($old)
        ->and($endpoint->serverUsername())->toBe('alex')
        ->and($endpoint->deliveries()->count())->toBe(1);

    $this->postJson("/webhooks/jellyfin/{$old}", jsonFixture('scrobble/jellyfin/progress-ping'))
        ->assertNotFound();
});

test('disconnecting a server takes its log with it', function () {
    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, JellyfinScrobbleSource::CODE);
    $endpoint->deliveries()->create(['status' => WebhookDeliveryStatus::Accepted]);

    $this->actingAs($user)
        ->delete("/settings/integrations/endpoints/{$endpoint->id}")
        ->assertRedirect();

    expect(WebhookEndpoint::count())->toBe(0)
        ->and(WebhookDelivery::count())->toBe(0);
});

test('the dry run places the sample title without touching the library', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie'))]);

    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, JellyfinScrobbleSource::CODE);

    $this->actingAs($user)
        ->post("/settings/integrations/endpoints/{$endpoint->id}/test")
        ->assertRedirect();

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Test)
        ->and($delivery->message)->toBe('test_passed')
        ->and($delivery->media_label)->toBe('The Matrix (1999)')
        ->and($user->plays()->count())->toBe(0);
});

test("Tautulli's dry run places the sample title too", function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie'))]);

    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, TautulliScrobbleSource::CODE);

    $this->actingAs($user)->post("/settings/integrations/endpoints/{$endpoint->id}/test");

    expect(WebhookDelivery::query()->firstOrFail())
        ->message->toBe('test_passed')
        ->media_label->toBe('The Matrix (1999)');
});

test('the dry run passes with the provider down when the sample carries its own TMDB id', function () {
    Http::fake(['*' => Http::response([], Response::HTTP_INTERNAL_SERVER_ERROR)]);

    $user = User::factory()->create();
    $endpoint = scrobbleEndpoint($user, PlexScrobbleSource::CODE);

    $this->actingAs($user)->post("/settings/integrations/endpoints/{$endpoint->id}/test");

    // Plex's sample carries a tmdb id, which needs nobody's help: the
    // resolver answers from the id itself and the run passes regardless.
    expect(WebhookDelivery::query()->firstOrFail()->status)->toBe(WebhookDeliveryStatus::Test);
});

test("somebody else's endpoint does not exist as far as this page is concerned", function () {
    $mine = User::factory()->create();
    $theirs = scrobbleEndpoint(User::factory()->create(), JellyfinScrobbleSource::CODE);

    $this->actingAs($mine)
        ->patch("/settings/integrations/endpoints/{$theirs->id}", ['enabled' => false])
        ->assertNotFound();

    $this->actingAs($mine)->post("/settings/integrations/endpoints/{$theirs->id}/rotate")->assertNotFound();
    $this->actingAs($mine)->post("/settings/integrations/endpoints/{$theirs->id}/test")->assertNotFound();
    $this->actingAs($mine)->delete("/settings/integrations/endpoints/{$theirs->id}")->assertNotFound();

    expect(WebhookEndpoint::query()->firstOrFail()->enabled)->toBeTrue();
});

test('the page is not open to strangers', function () {
    $this->get('/settings/integrations')->assertRedirect('/login');
});

test("each endpoint shows its own latest deliveries, every endpoint's read in one query", function () {
    $user = User::factory()->create();
    $endpoints = collect(['jellyfin', 'plex', 'emby'])->map(fn (string $source) => WebhookEndpoint::factory()->for($user)->create(['source' => $source]));

    foreach ($endpoints as $endpoint) {
        foreach (range(1, 25) as $ignored) {
            $endpoint->deliveries()->create(['status' => WebhookDeliveryStatus::Accepted]);
        }
    }

    $newest = $endpoints[0]->deliveries()->max('id');
    DB::enableQueryLog();

    $this->actingAs($user)->get('/settings/integrations')->assertInertia(fn (Assert $page) => $page
        ->where('sources.0.endpoints.0.deliveries.0.id', $newest)
        ->where('sources.0.endpoints.0.deliveries', fn ($deliveries) => count($deliveries) < 25));

    expect(collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'webhook_deliveries')))->toHaveCount(1);
});
