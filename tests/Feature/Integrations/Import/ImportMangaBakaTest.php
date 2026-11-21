<?php

use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Enums\TaskRunStatus;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Connections\ConnectMethod;
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
 * MangaBaka is exercised on the shapes of its own API schema (fixtures in
 * tests/Fixtures/transfer/mangabaka) — never live.
 */

beforeEach(function () {
    // Before any registry is resolved: offered with manga from MyAnimeList.
    enableMangaModule();
    Http::preventStrayRequests();
});

function mangaBakaConnection(User $user): ServiceConnection
{
    return activeConnection($user, MangaBakaService::CODE, ['token' => 'mb-token-of-alex']);
}

function fakeMangaBakaLibrary(): void
{
    Http::fake(['api.mangabaka.org/v2/my/library*' => fn (Request $request) => Http::response(jsonFixture(
        $request['page'] === '2' ? 'transfer/mangabaka/library-2' : 'transfer/mangabaka/library-1',
    ))]);
}

/**
 * The profile MangaBaka gives for a personal token with these scopes.
 *
 * @param  list<string>  $scopes
 * @return array<string, mixed>
 */
function mangaBakaProfile(array $scopes): array
{
    $profile = jsonFixture('transfer/mangabaka/profile');
    $profile['data']['scopes'] = $scopes;

    return $profile;
}

function fakeMangaBakaProfile(): void
{
    Http::fake(['api.mangabaka.org/v1/my/profile' => Http::response(mangaBakaProfile(['library.read', 'stack.read']))]);
}

test('without manga from MyAnimeList, MangaBaka is not offered', function () {
    config(['tracker.mal.client_id' => '']);
    app()->forgetInstance(ImportSourceRegistry::class);

    expect(app(ImportSourceRegistry::class)->has(MangaBakaSource::CODE))->toBeFalse();
});

test('the import page offers MangaBaka with a token form, nothing for the operator to set up', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.import.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources', fn ($sources) => collect($sources)->contains(fn ($source) => $source['code'] === MangaBakaSource::CODE
                && $source['connect']['method'] === ConnectMethod::Form->value
                && array_column($source['connect']['fields'], 'key') === ['token'])));
});

test('connecting keeps the token, under the account it belongs to', function () {
    fakeMangaBakaProfile();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('settings.connections.verify', MangaBakaService::CODE), ['values' => ['token' => 'mb-token-of-alex']])
        ->assertOk()
        ->assertJsonPath('choices', []);

    $this->actingAs($user)
        ->postJson(route('settings.connections.connect', MangaBakaService::CODE), ['values' => ['token' => ' mb-token-of-alex ']])
        ->assertOk()
        ->assertJsonPath('connection.account', 'alex');

    expect($user->serviceConnections()->sole()->credential('token'))->toBe('mb-token-of-alex');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.mangabaka.org/v1/my/profile'
        && $request->header('x-api-key') === ['mb-token-of-alex']);
});

test('a token is turned down once, with the reason', function (?int $status, array $scopes, string $token, string $message) {
    $status === null ? Http::fake() : Http::fake(['api.mangabaka.org/*' => $status === 200
        ? Http::response(mangaBakaProfile($scopes))
        : Http::response(['status' => $status, 'message' => 'No.'], $status)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.verify', MangaBakaService::CODE), ['values' => ['token' => $token]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => $message]);

    expect(requestsTo('/v1/my/profile'))->toBe($status === null ? 0 : 1);
})->with([
    'not a MangaBaka token' => [null, [], 'abc123', 'A MangaBaka token starts with mb-.'],
    'a wrong token' => [401, [], 'mb-wrong', 'MangaBaka did not accept this token.'],
    'a token without the library' => [200, ['stack.read'], 'mb-stacks-only', 'This token cannot read your library. Make one with library access.'],
    'a request MangaBaka refuses' => [400, [], 'mb-odd', 'MangaBaka answered 400 to /v1/my/profile.'],
]);

test('MangaBaka out of reach or failing is said so, not taken for a wrong token', function (?int $status, string $message) {
    Http::fake(['api.mangabaka.org/*' => $status === null ? Http::failedConnection() : Http::response(['status' => $status], $status)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.verify', MangaBakaService::CODE), ['values' => ['token' => 'mb-token-of-alex']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => $message]);
})->with([
    'unreachable' => [null, 'MangaBaka is unreachable: Could not resolve host: api.mangabaka.org'],
    'failing' => [503, 'MangaBaka answered 503; try again later.'],
]);

test('an account without a username is named by its nickname', function () {
    $profile = mangaBakaProfile(['library.read']);
    $profile['data']['preferred_username'] = null;
    Http::fake(['api.mangabaka.org/v1/my/profile' => Http::response($profile)]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('settings.connections.connect', MangaBakaService::CODE), ['values' => ['token' => 'mb-token-of-alex']])
        ->assertOk()
        ->assertJsonPath('connection.account', 'Alex');
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
        && $request->header('x-api-key') === ['mb-token-of-alex']);
});

test('reading the same library again adds nothing', function () {
    collect([25, 13, 2, 33327, 656])->each(fn (int $id) => MediaItem::factory()->manga()->create(['provider_ref' => (string) $id]));
    $connection = mangaBakaConnection(User::factory()->create());
    fakeMangaBakaLibrary();

    runConnectionImport($connection, MangaBakaSource::CODE);
    $again = runConnectionImport($connection, MangaBakaSource::CODE);

    expect($again->summary)->toContain('plays: 0', 'ratings: 0', 'statuses: 0', 'list items: 0');
});

test('a library read MangaBaka refuses ends the run once; a token it no longer honours expires the connection', function (int $status, ConnectionStatus $after) {
    $connection = mangaBakaConnection(User::factory()->create());
    Http::fake(['api.mangabaka.org/*' => Http::response(['status' => $status, 'message' => 'No.'], $status)]);

    $taskRun = runConnectionImport($connection, MangaBakaSource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($connection->refresh()->status)->toBe($after)
        ->and(requestsTo('/v2/my/library'))->toBe(1);
})->with([
    'a token without the library scope' => [403, ConnectionStatus::Active],
    'a token deleted' => [401, ConnectionStatus::Expired],
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
