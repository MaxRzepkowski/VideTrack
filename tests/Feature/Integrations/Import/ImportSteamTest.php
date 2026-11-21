<?php

use App\Catalog\MediaTypeRegistry;
use App\Enums\PlayOutcome;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\Steam\SteamClient;
use App\Jobs\RunImport;
use App\Models\MediaItem;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/*
 * Steam is exercised on the shapes in tests/Fixtures/transfer/steam (see the
 * README there) — never live.
 */

beforeEach(function () {
    Sleep::fake();
});

function enableSteam(): void
{
    enableGamesModule();
}

function runSteamImport(User $user, string $profile, bool $unplayed = false): TaskRun
{
    $taskRun = TaskRun::queueTask('import-steam', $user->id);

    app()->call([
        new RunImport($taskRun, 'steam', ImportInput::credentials(['profile' => $profile, 'key' => 'steam-key-of-alex', 'unplayed' => $unplayed ? '1' : '']), ImportSelection::everything()),
        'handle',
    ]);

    return $taskRun->refresh();
}

/**
 * IGDB knows Hollow Knight (14593), Silksong (115289) and TF2 (446) by
 * their Steam app ids; the soundtrack is not a game there.
 *
 * @return array<string, mixed>
 */
function steamIgdbFakes(): array
{
    $games = ['367520' => 14593, '1030300' => 115289, '440' => 446];

    return [
        'api.igdb.com/v4/external_games' => function (Request $request) use ($games) {
            preg_match('/uid = "(\d+)"/', $request->body(), $match);

            return Http::response(isset($games[$match[1] ?? '']) ? [['id' => 1, 'game' => $games[$match[1]]]] : []);
        },
    ];
}

test('Steam is offered with the game type, with a field for the user\'s own key and where to get it', function () {
    expect(app(ImportSourceRegistry::class)->has('steam'))->toBeFalse();

    // The modules an instance runs are fixed at boot; switching one on
    // mid-test means building the registries again.
    app()->forgetInstance(ImportSourceRegistry::class);
    app()->forgetInstance(MediaTypeRegistry::class);
    enableGamesModule();
    $steam = app(ImportSourceRegistry::class)->get('steam');

    expect(array_map(fn ($field) => $field->key, $steam->credentialsSchema()))->toBe(['profile', 'key', 'unplayed'])
        ->and($steam instanceof ExplainsSetup ? $steam->notes()[0]->url : null)->toBe(SteamClient::KEY_URL);
});

test('played games come over finished, with their time and last launch', function () {
    enableSteam();
    Http::fake([
        'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response(jsonFixture('transfer/steam/owned-games')),
        ...steamIgdbFakes(),
    ]);
    $knight = MediaItem::factory()->game()->create(['provider_ref' => '14593']);
    $silksong = MediaItem::factory()->game()->create(['provider_ref' => '115289']);
    $tf2 = MediaItem::factory()->game()->create(['provider_ref' => '446', 'release_date' => '2007-10-10']);
    $user = User::factory()->create();

    $taskRun = runSteamImport($user, '76561197960287930');

    expect($taskRun->status)->toBe(TaskRunStatus::Success);

    $play = $user->plays()->where('media_item_id', $knight->id)->sole();
    expect($play->outcome)->toBe(PlayOutcome::Completed)
        ->and($play->amount)->toBe(2710)
        ->and($play->watched_at->toIso8601String())->toBe('2024-01-01T00:00:00+00:00');

    // No last launch recorded: the play takes the release date.
    expect($user->plays()->where('media_item_id', $tf2->id)->sole()->watched_at->toDateString())->toBe('2007-10-10');

    // Never started, box not ticked: nothing at all.
    expect($user->plays()->where('media_item_id', $silksong->id)->exists())->toBeFalse()
        ->and($user->lists()->where('system_key', 'watchlist')->first()?->items()->where('media_item_id', $silksong->id)->exists() ?? false)->toBeFalse();

    // Placed by app id only; the unknown one is reported, not guessed by name.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'external_games')
        && $request->body() === 'fields game; where uid = "367520" & external_game_source = 1; limit 1;');
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/v4/games') && str_contains($request->body(), 'search'));
    expect($taskRun->errors)->toContain('Some Soundtrack (Steam 999999): no match for steam 999999.');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'GetOwnedGames')
        && $request['steamid'] === '76561197960287930'
        && $request['include_appinfo'] === '1'
        && $request['include_played_free_games'] === '1'
        && $request['key'] === 'steam-key-of-alex');
});

test('games never started go on the watchlist only when asked', function () {
    // More requests than the provider allows per second: its rate limiter
    // waits for the window to pass, which a frozen clock never lets happen.
    $this->travelBack();

    enableSteam();
    Http::fake([
        'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response(jsonFixture('transfer/steam/owned-games')),
        ...steamIgdbFakes(),
    ]);
    MediaItem::factory()->game()->create(['provider_ref' => '14593']);
    $silksong = MediaItem::factory()->game()->create(['provider_ref' => '115289']);
    MediaItem::factory()->game()->create(['provider_ref' => '446']);
    $user = User::factory()->create();

    runSteamImport($user, '76561197960287930', unplayed: true);

    expect($user->lists()->where('system_key', 'watchlist')->firstOrFail()->items()->where('media_item_id', $silksong->id)->exists())->toBeTrue()
        ->and($user->plays()->where('media_item_id', $silksong->id)->exists())->toBeFalse();
});

test('a profile address or name is turned into the SteamID64', function (string $typed, bool $resolves) {
    enableSteam();
    Http::fake([
        'api.steampowered.com/ISteamUser/ResolveVanityURL/*' => Http::response(jsonFixture('transfer/steam/vanity-found')),
        'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response(['response' => ['game_count' => 0, 'games' => []]]),
    ]);

    expect(runSteamImport(User::factory()->create(), $typed)->status)->toBe(TaskRunStatus::Success);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'GetOwnedGames') && $request['steamid'] === '76561197960287930');

    if ($resolves) {
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'ResolveVanityURL') && $request['vanityurl'] === 'gabelogannewell' && $request['key'] === 'steam-key-of-alex');
    } else {
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'ResolveVanityURL'));
    }
})->with([
    'SteamID64' => ['76561197960287930', false],
    'profiles address' => ['https://steamcommunity.com/profiles/76561197960287930/', false],
    'id address' => ['https://steamcommunity.com/id/gabelogannewell/', true],
    'bare name' => ['gabelogannewell', true],
]);

test('a profile Steam does not know is said so on the page', function () {
    enableSteam();
    Http::fake(['api.steampowered.com/ISteamUser/ResolveVanityURL/*' => Http::response(jsonFixture('transfer/steam/vanity-missing'))]);

    $this->actingAs(User::factory()->create())
        ->post(route('settings.import.analyze', ['source' => 'steam']), ['credentials' => ['profile' => 'nobody-here', 'key' => 'steam-key-of-alex']])
        ->assertOk()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('error', 'Steam knows no profile by that name.');
});

test('a private library is explained, not imported as empty', function () {
    enableSteam();
    Http::fake(['api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response(['response' => []])]);

    $this->actingAs(User::factory()->create())
        ->post(route('settings.import.analyze', ['source' => 'steam']), ['credentials' => ['profile' => '76561197960287930', 'key' => 'steam-key-of-alex']])
        ->assertJsonPath('valid', false)
        ->assertJsonPath('error', 'Steam shows no games on this profile. In Steam, set Privacy → Game details to Public.');
});

test('a rejected key is asked about once', function () {
    enableSteam();
    Http::fake(['api.steampowered.com/*' => Http::response('Forbidden', 403)]);

    $this->actingAs(User::factory()->create())
        ->post(route('settings.import.analyze', ['source' => 'steam']), ['credentials' => ['profile' => '76561197960287930', 'key' => 'wrong-key']])
        ->assertJsonPath('error', 'Steam did not accept this API key.');

    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'steampowered'))->count())->toBe(1);
});

test('the watchlist box is optional and travels with the run', function () {
    enableSteam();
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('settings.import.store'), ['source' => 'steam', 'credentials' => ['profile' => 'gabelogannewell', 'key' => 'steam-key-of-alex']])
        ->assertSessionHasNoErrors();

    $this->post(route('settings.import.store'), ['source' => 'steam', 'credentials' => ['profile' => 'gabelogannewell', 'key' => 'steam-key-of-alex', 'unplayed' => true]])
        ->assertSessionHasNoErrors();

    $flags = [];
    Queue::assertPushed(RunImport::class, function (RunImport $job) use (&$flags): bool {
        $input = (fn () => $this->input)->call($job);
        $flags[] = [$input->credential('unplayed'), $input->credential('key')];

        return true;
    });

    // The key reaches the run, sealed: never in the clear in the job.
    expect($flags)->toBe([['', 'steam-key-of-alex'], ['1', 'steam-key-of-alex']]);
    Queue::assertPushed(RunImport::class, fn (RunImport $job) => ! str_contains(serialize($job), 'steam-key-of-alex'));
});

test('an import without a key is refused, and a key typed into a refused form stays out of the session', function () {
    enableSteam();
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('settings.import.store'), ['source' => 'steam', 'credentials' => ['profile' => 'gabelogannewell']])
        ->assertSessionHasErrors('credentials.key');

    $this->post(route('settings.import.store'), ['source' => 'steam', 'credentials' => ['profile' => str_repeat('x', 3000), 'key' => 'steam-key-of-alex']])
        ->assertSessionHasErrors();

    expect(session()->getOldInput('credentials'))->toBeNull();
    Queue::assertNothingPushed();
});

test('the key is not kept with the run', function () {
    enableSteam();
    Http::fake(['api.steampowered.com/*' => Http::response(['response' => ['game_count' => 0, 'games' => []]])]);
    $user = User::factory()->create();

    $taskRun = runSteamImport($user, '76561197960287930');

    expect(json_encode($taskRun->toArray()))->not->toContain('steam-key-of-alex');
});
