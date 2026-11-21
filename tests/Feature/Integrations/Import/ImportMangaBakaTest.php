<?php

use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Enums\TaskRunStatus;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Connections\ConnectMethod;
use App\Integrations\Connections\RedirectAttempts;
use App\Integrations\Connections\TokenGrantClient;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\MangaBaka\MangaBakaClient;
use App\Integrations\MangaBaka\MangaBakaService;
use App\Integrations\MangaBaka\MangaBakaSource;
use App\Jobs\RunImport;
use App\Models\MediaItem;
use App\Models\ServiceConnection;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/*
 * MangaBaka is exercised on the shapes of its own API schema and OAuth
 * guide (fixtures in tests/Fixtures/transfer/mangabaka) — never live.
 */

beforeEach(function () {
    // Before any registry is resolved: offered only with a registered app
    // and manga from MyAnimeList.
    enableMangaModule();
    config(['tracker.mangabaka.client_id' => 'mangabaka-client', 'tracker.mangabaka.client_secret' => 'secret-of-the-app']);
    Http::preventStrayRequests();
});

/**
 * Starts a trip to MangaBaka as $user; the authorize address's query.
 *
 * @return array<string, string>
 */
function mangaBakaTrip(User $user): array
{
    $url = (string) test()->actingAs($user)->postJson(route('settings.connections.redirect.begin', MangaBakaService::CODE))->assertOk()->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return array_filter($query, is_string(...));
}

function mangaBakaConnection(User $user): ServiceConnection
{
    return deviceConnection($user, MangaBakaService::CODE);
}

function fakeMangaBakaLibrary(): void
{
    Http::fake(['api.mangabaka.org/v2/my/library*' => fn (Request $request) => Http::response(jsonFixture(
        $request['page'] === '2' ? 'transfer/mangabaka/library-2' : 'transfer/mangabaka/library-1',
    ))]);
}

test('without a registered app, or manga from MyAnimeList, MangaBaka is not offered', function (array $config) {
    config($config);
    app()->forgetInstance(ImportSourceRegistry::class);

    expect(app(ImportSourceRegistry::class)->has(MangaBakaSource::CODE))->toBeFalse();
})->with([
    'no secret' => [['tracker.mangabaka.client_secret' => '']],
    'no manga' => [['tracker.mal.client_id' => '']],
]);

test('the import page offers MangaBaka with a trip to mangabaka.org', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.import.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources', fn ($sources) => collect($sources)->contains(fn ($source) => $source['code'] === MangaBakaSource::CODE
                && $source['connect']['method'] === ConnectMethod::Redirect->value)));
});

test('connecting sends the user to approve the app with the attempt\'s state and a PKCE challenge', function () {
    $query = mangaBakaTrip(User::factory()->create());

    expect($query)->toMatchArray([
        'response_type' => 'code',
        'client_id' => 'mangabaka-client',
        'redirect_uri' => route('settings.connections.callback', MangaBakaService::CODE),
        'scope' => 'openid profile library.read offline_access',
        'code_challenge_method' => 'S256',
    ])
        ->and($query['state'] ?? '')->not->toBe('')
        // S256 of a verifier, base64url without padding.
        ->and($query['code_challenge'] ?? '')->toMatch('/^[A-Za-z0-9_-]{43}$/');
});

test('the code that comes back is exchanged with the kept verifier and the app\'s secret, and connects the account', function () {
    $user = User::factory()->create();
    $query = mangaBakaTrip($user);

    Http::fake([
        'mangabaka.org/auth/oauth2/token' => Http::response(['access_token' => 'access-of-alex', 'token_type' => 'Bearer', 'expires_in' => 604800, 'refresh_token' => 'refresh-of-alex', 'scope' => 'openid profile library.read offline_access']),
        'mangabaka.org/auth/oauth2/userinfo' => Http::response(['sub' => 'u1', 'preferred_username' => 'alex']),
    ]);

    $this->get(route('settings.connections.callback', [MangaBakaService::CODE, 'code' => 'code-of-alex', 'state' => $query['state']]))
        ->assertRedirect(route('settings.import.index', ['source' => MangaBakaSource::CODE]));

    $connection = $user->serviceConnections()->sole();

    expect($connection->credential('access_token'))->toBe('access-of-alex')
        ->and($connection->credential('refresh_token'))->toBe('refresh-of-alex')
        ->and($connection->account_label)->toBe('alex');

    $exchange = Http::recorded(fn (Request $request) => str_contains($request->url(), '/oauth2/token'))->sole()[0];

    expect($exchange['grant_type'])->toBe('authorization_code')
        ->and($exchange['code'])->toBe('code-of-alex')
        ->and($exchange['redirect_uri'])->toBe(route('settings.connections.callback', MangaBakaService::CODE))
        ->and($exchange['client_id'])->toBe('mangabaka-client')
        ->and($exchange['client_secret'])->toBe('secret-of-the-app');

    $verifier = $exchange['code_verifier'];

    // The verifier sent is the one the challenge was made from.
    expect(rtrim(strtr(base64_encode(hash('sha256', (string) $verifier, true)), '+/', '-_'), '='))->toBe($query['code_challenge']);
});

test('a user who said no on MangaBaka comes back to the reason, and no connection', function () {
    $user = User::factory()->create();
    $query = mangaBakaTrip($user);

    $this->get(route('settings.connections.callback', [MangaBakaService::CODE, 'error' => 'access_denied', 'state' => $query['state']]))
        ->assertSessionHasErrors([RedirectAttempts::errorKey(MangaBakaService::CODE) => 'The connection was not approved on MangaBaka.']);

    expect($user->serviceConnections()->exists())->toBeFalse();
});

test('the library comes over: reads, reading, on hold, dropped, wanted, ratings and lists', function () {
    $items = collect([25, 13, 2, 33327, 656])->mapWithKeys(fn (int $id) => [$id => MediaItem::factory()->manga()->create(['provider_ref' => (string) $id])]);
    $user = User::factory()->create();
    fakeMangaBakaLibrary();

    $taskRun = runConnectionImport(mangaBakaConnection($user), MangaBakaSource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe(['A MangaBaka series without a title.'])
        // Completed once on its day, though MangaBaka counts a re-read without dates.
        ->and($user->plays()->where('media_item_id', $items[25]->id)->where('outcome', PlayOutcome::Completed->value)->pluck('watched_at')->map->toDateString()->all())->toBe(['2024-03-01'])
        ->and($user->ratings()->where('media_item_id', $items[25]->id)->value('rating'))->toBe(9)
        ->and($user->mediaStates()->where('media_item_id', $items[13]->id)->sole()->status)->toBe(MediaUserStatus::InProgress)
        ->and($user->mediaStates()->where('media_item_id', $items[2]->id)->sole()->status)->toBe(MediaUserStatus::OnHold)
        ->and($user->plays()->where('media_item_id', $items[33327]->id)->where('outcome', PlayOutcome::Dropped->value)->sole()->watched_at->toDateString())->toBe('2021-04-10')
        ->and($user->ratings()->where('media_item_id', $items[33327]->id)->value('rating'))->toBe(3)
        ->and($user->watchlist()->items()->pluck('media_item_id')->all())->toBe([$items[656]->id])
        ->and($user->lists()->where('title', 'Favourites')->sole()->items()->pluck('media_item_id')->sort()->values()->all())->toBe(collect([$items[13]->id, $items[25]->id])->sort()->values()->all())
        ->and($user->lists()->where('title', 'Secret Stash')->sole()->items()->pluck('media_item_id')->all())->toBe([$items[13]->id])
        // Private on MangaBaka, private here.
        ->and($user->lists()->where('title', 'Secret Stash')->sole()->is_public)->toBeFalse()
        ->and($user->lists()->where('title', 'Favourites')->sole()->is_public)->toBeTrue();

    expect(requestsTo('/v2/my/library'))->toBe(2);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v2/my/library')
        && $request['limit'] === (string) MangaBakaClient::PAGE_SIZE
        && $request['sort_by'] === 'created_at_asc'
        && $request->header('Authorization') === ['Bearer access-of-alex-one']);
});

test('reading the same library again adds nothing', function () {
    collect([25, 13, 2, 33327, 656])->each(fn (int $id) => MediaItem::factory()->manga()->create(['provider_ref' => (string) $id]));
    $connection = mangaBakaConnection(User::factory()->create());
    fakeMangaBakaLibrary();

    runConnectionImport($connection, MangaBakaSource::CODE);
    $again = runConnectionImport($connection, MangaBakaSource::CODE);

    expect($again->summary)->toContain('plays: 0', 'ratings: 0', 'statuses: 0', 'list items: 0');
});

test('a refresh is signed with the app\'s secret, and the rotated refresh token kept', function () {
    $connection = deviceConnection(User::factory()->create(), MangaBakaService::CODE, ['expires_at' => now()->addSeconds(TokenGrantClient::REFRESH_MARGIN_SECONDS - 3600)->getTimestamp()]);
    Http::fake([
        'mangabaka.org/auth/oauth2/token' => Http::response(['access_token' => 'access-of-alex-two', 'expires_in' => 604800, 'refresh_token' => 'refresh-of-alex-two']),
        'api.mangabaka.org/*' => Http::response(['status' => 200, 'pagination' => ['next' => null], 'data' => []]),
    ]);

    runConnectionImport($connection, MangaBakaSource::CODE);

    expect($connection->refresh()->credential('refresh_token'))->toBe('refresh-of-alex-two');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth2/token')
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'refresh-of-alex-one'
        && $request['client_secret'] === 'secret-of-the-app');
});

test('disconnecting revokes the grant at MangaBaka', function () {
    Http::fake(['mangabaka.org/auth/oauth2/revoke' => Http::response([])]);
    $user = User::factory()->create();
    $connection = mangaBakaConnection($user);

    $this->actingAs($user)->delete(route('settings.connections.destroy', $connection))->assertRedirect();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth2/revoke')
        && $request['token'] === 'refresh-of-alex-one'
        && $request['client_id'] === 'mangabaka-client');
});

test('an app MangaBaka does not know is said so when the code comes back', function () {
    $user = User::factory()->create();
    $query = mangaBakaTrip($user);
    Http::fake(['mangabaka.org/auth/oauth2/token' => Http::response(['error' => 'invalid_client', 'error_description' => 'Client authentication failed.'], 401)]);

    $this->get(route('settings.connections.callback', [MangaBakaService::CODE, 'code' => 'code-of-alex', 'state' => $query['state']]))
        ->assertSessionHasErrors([RedirectAttempts::errorKey(MangaBakaService::CODE) => 'MangaBaka did not accept this instance. Its client id and secret must belong to a Web app.']);
});

test('a library read MangaBaka refuses ends the run once; a grant it no longer honours expires the connection', function (int $status, ConnectionStatus $after) {
    $connection = mangaBakaConnection(User::factory()->create());
    Http::fake([
        'mangabaka.org/auth/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
        'api.mangabaka.org/*' => Http::response(['status' => $status, 'message' => 'No.'], $status),
    ]);

    $taskRun = runConnectionImport($connection, MangaBakaSource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($connection->refresh()->status)->toBe($after);
})->with([
    'a token without the library scope' => [403, ConnectionStatus::Active],
    'a grant gone' => [401, ConnectionStatus::Expired],
]);

test('a 429 leaves a fresh run for as long as MangaBaka asks', function () {
    $connection = mangaBakaConnection(User::factory()->create());
    Http::fake(['api.mangabaka.org/*' => Http::response(['status' => 429, 'message' => 'Slow down.'], 429, ['Retry-After' => '30'])]);
    $taskRun = TaskRun::queueTask(ImportSourceRegistry::taskName(MangaBakaSource::CODE), $connection->user_id);
    $job = new RunImport($taskRun, MangaBakaSource::CODE, ImportInput::connection($connection->id), ImportSelection::everything());

    app()->call([$job, 'handle']);

    $later = $job->handOver();
    expect($later instanceof RunImport ? $later->delay : null)->toBe(30);
});
