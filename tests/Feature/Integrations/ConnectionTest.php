<?php

use App\Actions\Users\DeleteAccount;
use App\Enums\TaskRunStatus;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Connections\ConnectMethod;
use App\Integrations\Connections\Contracts\AuthorizesByForm;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\DeviceAttempts;
use App\Integrations\Connections\DevicePoll;
use App\Integrations\Connections\RedirectAttempts;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportInputKind;
use App\Integrations\Import\ImportSourceRegistry;
use App\Jobs\RunImport;
use App\Models\ServiceConnection;
use App\Models\TaskRun;
use App\Models\User;
use App\Support\Forms\CredentialField;
use App\Support\Forms\CredentialFieldType;
use App\Support\PublicUrl;
use App\Support\ServiceBusy;
use App\Support\ServiceUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Fakes\FakeConnectionImportSource;
use Tests\Fakes\FakeDeviceService;
use Tests\Fakes\FakeFormService;
use Tests\Fakes\FakeRedirectService;

beforeEach(function () {
    $connections = app(ConnectionRegistry::class);
    $connections->register(FakeDeviceService::CODE, FakeDeviceService::class);
    $connections->register(FakeFormService::CODE, FakeFormService::class);

    app(ImportSourceRegistry::class)->register(FakeConnectionImportSource::CODE, FakeConnectionImportSource::class);
});

function grant(string $token = 'at-1'): ConnectionGrant
{
    return new ConnectionGrant(['access_token' => $token, 'refresh_token' => 'rt-1'], 'someone');
}

function connectionFor(User $user, array $attributes = []): ServiceConnection
{
    return ServiceConnection::factory()->create([
        'user_id' => $user->id,
        'service' => FakeDeviceService::CODE,
        'credentials' => ['access_token' => 'at-1'],
        'status' => ConnectionStatus::Active,
        'account_label' => 'someone',
        ...$attributes,
    ]);
}

test('starting a device flow shows the code to type and keeps the device code on the server', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/settings/connections/fake-device/device')
        ->assertOk()
        ->assertJsonPath('userCode', 'ABCD-EFGH')
        ->assertJsonPath('verificationUriComplete', 'https://example.test/pin?user_code=ABCD-EFGH')
        ->assertJsonPath('interval', 5)
        ->assertJsonMissing(['deviceCode' => 'secret-device-code'])
        ->assertDontSee('secret-device-code');
});

test('what the card asked for rides with every poll, and rests encrypted', function () {
    FakeDeviceService::$fields = [new CredentialField('client_id', 'App id')];
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('settings.connections.device.begin', FakeDeviceService::CODE), ['values' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['values.client_id']);

    $this->postJson(route('settings.connections.device.begin', FakeDeviceService::CODE), ['values' => ['client_id' => ' app-of-alex ']])->assertOk();

    $stored = Cache::get("connections:device:{$user->id}:".FakeDeviceService::CODE);
    expect(serialize($stored))->not->toContain('app-of-alex');

    $this->travel(5)->seconds();
    $this->postJson(route('settings.connections.device.poll', FakeDeviceService::CODE));

    expect(FakeDeviceService::$seenValues)->toBe([['client_id' => 'app-of-alex']]);
});

test('an attempt kept without what was typed reads as gone', function () {
    $user = User::factory()->create();
    Cache::put("connections:device:{$user->id}:".FakeDeviceService::CODE, [
        'device_code' => Crypt::encryptString('secret-device-code'),
        'interval' => 5,
        'next_poll_at' => now()->getTimestamp(),
        'expires_at' => now()->addMinutes(10)->getTimestamp(),
    ], 600);

    $this->actingAs($user)->postJson(route('settings.connections.device.poll', FakeDeviceService::CODE))->assertJsonPath('status', 'expired');
    expect(FakeDeviceService::$pollCount)->toBe(0);
});

test('the device code rests encrypted in the cache', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/settings/connections/fake-device/device')->assertOk();

    $stored = Cache::get("connections:device:{$user->id}:".FakeDeviceService::CODE);

    expect($stored['device_code'])->not->toBe('secret-device-code')
        ->and(Crypt::decryptString($stored['device_code']))->toBe('secret-device-code');
});

test('a poll before the interval is up never reaches the service', function () {
    $this->actingAs(User::factory()->create())->postJson('/settings/connections/fake-device/device');

    $this->postJson('/settings/connections/fake-device/device/poll')->assertJsonPath('status', 'pending');

    expect(FakeDeviceService::$pollCount)->toBe(0);

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll')->assertJsonPath('status', 'pending');

    expect(FakeDeviceService::$pollCount)->toBe(1)
        ->and(FakeDeviceService::$seenDeviceCodes)->toBe(['secret-device-code']);
});

test('slow_down makes the next poll wait five seconds longer', function () {
    FakeDeviceService::$polls = [DevicePoll::SlowDown, DevicePoll::Pending];

    $this->actingAs(User::factory()->create())->postJson('/settings/connections/fake-device/device');

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll')->assertJsonPath('status', 'pending');
    expect(FakeDeviceService::$pollCount)->toBe(1);

    // The old interval would allow this one; the grown one does not.
    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll');
    expect(FakeDeviceService::$pollCount)->toBe(1);

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll');
    expect(FakeDeviceService::$pollCount)->toBe(2);
});

test('an approved device flow keeps the connection and answers with its card', function () {
    FakeDeviceService::$polls = [DevicePoll::Pending, grant()];
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/settings/connections/fake-device/device');

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll')->assertJsonPath('status', 'pending');

    $this->travel(5)->seconds();
    $response = $this->postJson('/settings/connections/fake-device/device/poll')
        ->assertJsonPath('status', 'connected')
        ->assertJsonPath('connection.service', 'fake-device')
        ->assertJsonPath('connection.account', 'someone')
        ->assertJsonPath('connection.status', 'active');

    expect($response->getContent())->not->toContain('at-1')->not->toContain('rt-1');

    $connection = $user->serviceConnections()->sole();
    expect($connection->credential('access_token'))->toBe('at-1')
        ->and(DB::table('service_connections')->value('credentials'))->not->toContain('at-1');

    // The attempt is spent: another poll does not reach the service.
    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll')->assertJsonPath('status', 'expired');
    expect(FakeDeviceService::$pollCount)->toBe(2);
});

test('a refusal that never says so ends at the code expiry', function () {
    // RFC 8628 promises access_denied; Simkl reports a refusal as pending
    // forever, so only the deadline stops the polling.
    $this->actingAs(User::factory()->create())->postJson('/settings/connections/fake-device/device');

    $this->travel(901)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll')->assertJsonPath('status', 'expired');

    expect(FakeDeviceService::$pollCount)->toBe(0)
        ->and(ServiceConnection::query()->count())->toBe(0);
});

test('an expired device code ends the attempt', function () {
    FakeDeviceService::$polls = [DevicePoll::Expired];

    $this->actingAs(User::factory()->create())->postJson('/settings/connections/fake-device/device');

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll')->assertJsonPath('status', 'expired');

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll')->assertJsonPath('status', 'expired');

    expect(FakeDeviceService::$pollCount)->toBe(1);
});

test('a rejected client stops the polling with its reason', function () {
    FakeDeviceService::$polls = [new ConnectionRejected('This instance is not registered with the service.')];

    $this->actingAs(User::factory()->create())->postJson('/settings/connections/fake-device/device');

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll')
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('message', 'This instance is not registered with the service.');

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll')->assertJsonPath('status', 'expired');
    expect(FakeDeviceService::$pollCount)->toBe(1);
});

test('a service out of reach when starting is said under the card, not as a server error', function () {
    FakeDeviceService::$beginFails = new ServiceUnavailable('import.service_unreachable', ['service' => 'Fake Device', 'reason' => 'timed out']);

    $this->actingAs(User::factory()->create())->postJson('/settings/connections/fake-device/device')
        ->assertUnprocessable()
        ->assertJsonPath('errors.connection.0', 'Fake Device is unreachable: timed out');
});

test('a poll the service could not answer is not yet, and the attempt goes on', function () {
    FakeDeviceService::$polls = [new ServiceUnavailable('import.service_unreachable', ['service' => 'Fake Device', 'reason' => 'timed out'])];

    $this->actingAs(User::factory()->create())->postJson('/settings/connections/fake-device/device');

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll')->assertJsonPath('status', 'pending');

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll')->assertJsonPath('status', 'pending');
    expect(FakeDeviceService::$pollCount)->toBe(2);
});

test('a slow poll about an old code neither ends nor replaces the attempt begun meanwhile', function (DevicePoll $answer) {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/settings/connections/fake-device/device');
    // The user starts over while the service is still answering the old code.
    FakeDeviceService::$polls = [function () use ($user, $answer): DevicePoll {
        FakeDeviceService::$deviceCode = 'newer-device-code';
        app(DeviceAttempts::class)->begin($user, new FakeDeviceService, FakeDeviceService::CODE, []);

        return $answer;
    }];

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll');

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll')->assertJsonPath('status', 'pending');
    expect(FakeDeviceService::$seenDeviceCodes)->toBe(['secret-device-code', 'newer-device-code']);
})->with([
    'still pending' => [DevicePoll::Pending],
    'expired' => [DevicePoll::Expired],
]);

test('cancelling a device flow forgets the attempt', function () {
    $this->actingAs(User::factory()->create())->postJson('/settings/connections/fake-device/device');

    $this->deleteJson('/settings/connections/fake-device/device')->assertOk();

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll')->assertJsonPath('status', 'expired');
    expect(FakeDeviceService::$pollCount)->toBe(0);
});

test('device endpoints exist only for device services', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/settings/connections/fake-form/device')
        ->assertNotFound();

    $this->postJson('/settings/connections/nope/device')->assertNotFound();
    $this->postJson('/settings/connections/fake-device/verify', ['values' => []])->assertNotFound();
});

test('a rejected key is reported under the form and asked about once', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/settings/connections/fake-form/verify', ['values' => ['url' => 'https://t.test', 'key' => 'wrong']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => 'The key was not accepted.']);

    expect(FakeFormService::$verifications)->toBe(1);
});

test('a checked key lists the accounts to pick from', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/settings/connections/fake-form/verify', ['values' => ['url' => 'https://t.test', 'key' => 'good-key']])
        ->assertOk()
        ->assertJsonPath('choices.0', ['id' => 'u-1', 'label' => 'alice'])
        ->assertJsonPath('choices.1.label', 'bob');
});

test('the form fields are required', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/settings/connections/fake-form/verify', ['values' => ['url' => 'https://t.test']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['values.key']);

    expect(FakeFormService::$verifications)->toBe(0);
});

test('connecting by form keeps the picked account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/settings/connections/fake-form', [
            'values' => ['url' => 'https://t.test', 'key' => 'good-key'],
            'choice' => 'u-2',
        ])
        ->assertOk()
        ->assertJsonPath('connection.account', 'bob');

    $connection = $user->serviceConnections()->sole();
    expect($connection->service)->toBe('fake-form')
        ->and($connection->setting('user_id'))->toBe('u-2')
        ->and($connection->credential('key'))->toBe('good-key');
});

test('connecting again replaces the grant, drops the read position and keeps auto-import', function () {
    $user = User::factory()->create();
    connectionFor($user, [
        'auto_import' => true,
        'state' => ['since' => '2026-01-01'],
        'status' => ConnectionStatus::Expired,
        'last_error' => 'The service stopped accepting this connection.',
    ]);
    FakeDeviceService::$polls = [grant('at-2')];

    $this->actingAs($user)->postJson('/settings/connections/fake-device/device');
    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/fake-device/device/poll')->assertJsonPath('status', 'connected');

    $connection = $user->serviceConnections()->sole();
    expect($connection->credential('access_token'))->toBe('at-2')
        ->and($connection->state)->toBeNull()
        ->and($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->last_error)->toBeNull()
        ->and($connection->auto_import)->toBeTrue();
});

test('auto-import is switched per connection, by its owner only', function () {
    $user = User::factory()->create();
    $connection = connectionFor($user);

    $this->actingAs($user)
        ->patch("/settings/connections/{$connection->id}", ['auto_import' => true])
        ->assertRedirect();

    expect($connection->refresh()->auto_import)->toBeTrue();

    $this->actingAs(User::factory()->create())
        ->patch("/settings/connections/{$connection->id}", ['auto_import' => false])
        ->assertNotFound();

    expect($connection->refresh()->auto_import)->toBeTrue();
});

test('disconnecting revokes at the service and forgets the connection', function () {
    $user = User::factory()->create();
    $connection = connectionFor($user);

    $this->actingAs($user)->delete("/settings/connections/{$connection->id}")->assertRedirect();

    expect(FakeDeviceService::$revoked)->toBe([$connection->id])
        ->and(ServiceConnection::query()->count())->toBe(0);
});

test('a revoke that fails never keeps the connection', function () {
    FakeDeviceService::$revokeFails = true;
    $user = User::factory()->create();
    $connection = connectionFor($user);

    $this->actingAs($user)->delete("/settings/connections/{$connection->id}")->assertRedirect();

    expect(ServiceConnection::query()->count())->toBe(0);
});

test('deleting the account revokes its connections at the service, a failing one included', function () {
    $user = User::factory()->create();
    $connection = connectionFor($user);

    app(DeleteAccount::class)->execute($user);

    expect(FakeDeviceService::$revoked)->toBe([$connection->id])
        ->and(ServiceConnection::query()->count())->toBe(0);

    FakeDeviceService::$revokeFails = true;
    $other = User::factory()->create();
    connectionFor($other);

    app(DeleteAccount::class)->execute($other);

    expect(User::query()->whereKey($other->id)->exists())->toBeFalse();
});

test('someone else cannot disconnect a connection', function () {
    $connection = connectionFor(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->delete("/settings/connections/{$connection->id}")
        ->assertNotFound();

    expect(ServiceConnection::query()->count())->toBe(1);
});

test('the import page shows how a connection source connects and what the user holds', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings/import')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources.0.connect', null)
            ->where('sources.'.(count(app(ImportSourceRegistry::class)->codes()) - 1).'.connect', [
                'service' => 'fake-device',
                'name' => 'Fake Device',
                'method' => 'device',
                'fields' => [],
                'autoImportMinutes' => 60,
                'connection' => null,
                'errorKey' => RedirectAttempts::errorKey('fake-device'),
            ]));

    connectionFor($user);

    $this->get('/settings/import')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources.'.(count(app(ImportSourceRegistry::class)->codes()) - 1).'.connect.connection.account', 'someone')
            ->missing('sources.'.(count(app(ImportSourceRegistry::class)->codes()) - 1).'.connect.connection.credentials'));
});

test('a connection source needs a connection that still reads', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/import', ['source' => FakeConnectionImportSource::CODE])
        ->assertSessionHasErrors(['source' => 'Connect your account first.']);

    connectionFor($user, ['status' => ConnectionStatus::Expired]);

    $this->post('/settings/import', ['source' => FakeConnectionImportSource::CODE])
        ->assertSessionHasErrors(['source' => 'Connect your account first.']);

    Queue::assertNothingPushed();
});

test('an import through a connection runs on that connection', function () {
    Queue::fake();
    $user = User::factory()->create();
    $connection = connectionFor($user);

    $this->actingAs($user)
        ->post('/settings/import', ['source' => FakeConnectionImportSource::CODE])
        ->assertSessionHasNoErrors();

    Queue::assertPushed(RunImport::class, function (RunImport $job) use ($connection): bool {
        $input = (fn () => $this->input)->call($job);

        return $input->kind === ImportInputKind::Connection && $input->connectionId === $connection->id;
    });
});

test('a run through a connection stores where it stopped for the next one', function () {
    $user = User::factory()->create();
    $connection = connectionFor($user, ['state' => ['since' => '2026-01-01T00:00:00Z'], 'last_error' => 'old']);
    FakeConnectionImportSource::$position = ['since' => '2026-09-27T10:00:00Z'];

    $taskRun = TaskRun::queueTask(ImportSourceRegistry::taskName(FakeConnectionImportSource::CODE), $user->id);
    app()->call([
        new RunImport($taskRun, FakeConnectionImportSource::CODE, ImportInput::connection($connection->id), ImportSelection::everything()),
        'handle',
    ]);

    $connection->refresh();

    expect(FakeConnectionImportSource::$startedFrom)->toBe(['since' => '2026-01-01T00:00:00Z'])
        ->and($connection->state)->toBe(['since' => '2026-09-27T10:00:00Z'])
        ->and($connection->last_imported_at)->not->toBeNull()
        ->and($connection->last_error)->toBeNull()
        ->and($taskRun->refresh()->summary)->toStartWith('Processed 0 entries.');
});

test('a failed run through a connection leaves its reason on the connection', function () {
    $user = User::factory()->create();
    $connection = connectionFor($user);

    $job = new RunImport(TaskRun::queueTask(ImportSourceRegistry::taskName(FakeConnectionImportSource::CODE), $user->id), FakeConnectionImportSource::CODE, ImportInput::connection($connection->id), ImportSelection::everything());
    $job->failed(new ServiceUnavailable('Simkl is down.'));

    expect($connection->refresh()->last_error)->toBe('Simkl is down.')
        ->and($connection->last_imported_at)->toBeNull();
});

function busyImportJob(User $user, ServiceConnection $connection): RunImport
{
    $taskRun = TaskRun::queueTask(ImportSourceRegistry::taskName(FakeConnectionImportSource::CODE), $user->id);

    return new RunImport($taskRun, FakeConnectionImportSource::CODE, ImportInput::connection($connection->id), ImportSelection::everything());
}

test('a service asking for a pause leaves a fresh run for that long, without spending a try', function () {
    $user = User::factory()->create();
    $connection = connectionFor($user);
    FakeConnectionImportSource::$failWith = new ServiceBusy('import.service_answered_try_later', 120, ['service' => 'Fake', 'status' => 429]);

    $job = busyImportJob($user, $connection)->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    $later = $job->handOver();
    expect($later)->toBeInstanceOf(RunImport::class)
        ->and($later)->not->toBe($job)
        ->and($later instanceof RunImport ? $later->delay : null)->toBe(120)
        ->and(TaskRun::query()->sole()->status)->not->toBe(TaskRunStatus::Failure)
        ->and($connection->refresh()->last_error)->toBeNull();
});

test('a pause longer than a run waits out, or one pause too many, ends the run with the reason', function (int $seconds, int $waitsBefore) {
    $user = User::factory()->create();
    $connection = connectionFor($user);
    FakeConnectionImportSource::$failWith = new ServiceBusy('import.service_answered_try_later', $seconds, ['service' => 'Fake', 'status' => 429]);

    $job = busyImportJob($user, $connection);

    // Each wait hands on a fresh copy; the last one is the one that gives up.
    for ($wait = 0; $wait < $waitsBefore; $wait++) {
        app()->call([$job, 'handle']);
        $later = $job->handOver();
        $job = $later instanceof RunImport ? $later : throw new LogicException('A busy run hands on a copy.');
    }

    app()->call([$job, 'handle']);

    expect($job->handOver())->toBeNull()
        ->and(TaskRun::query()->sole()->status)->toBe(TaskRunStatus::Failure)
        ->and(TaskRun::query()->sole()->summary)->toBe('Fake answered 429; try again later.')
        ->and($connection->refresh()->last_error)->toBe('Fake answered 429; try again later.');
})->with([
    'a day\'s wait' => [RunImport::LONGEST_BUSY_WAIT_SECONDS + 1, 0],
    'one pause too many' => [120, RunImport::MOST_BUSY_WAITS],
]);

test('every service with an address field keeps an ordinary account out of the server itself', function () {
    Http::fake();
    $registry = app(ConnectionRegistry::class);
    $checked = 0;

    foreach ($registry->codesImplementing(AuthorizesByForm::class) as $code) {
        $service = $registry->get($code);
        assert($service instanceof AuthorizesByForm);
        $values = [];

        foreach ($service->connectionFields() as $field) {
            $values[$field->key] = $field->type === CredentialFieldType::Url ? 'http://127.0.0.1:8080' : 'x';
        }

        foreach ($service->connectionFields() as $field) {
            if ($field->type === CredentialFieldType::Url) {
                $this->actingAs(User::factory()->create())
                    ->postJson(route('settings.connections.verify', ['service' => $code]), ['values' => $values])
                    ->assertJsonValidationErrors(["values.{$field->key}" => __('common.this_address_leads_to_the_server')]);
                $checked++;
            }
        }
    }

    expect($checked)->toBeGreaterThan(0);
    Http::assertNothingSent();
});

/**
 * Starts a trip to the fake redirect service as $user; the state the
 * attempt sent along, read off the address the page was given.
 */
function startTrip(User $user): string
{
    $url = (string) test()->actingAs($user)->postJson(route('settings.connections.redirect.begin', FakeRedirectService::CODE))->assertOk()->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return is_string($query['state'] ?? null) ? $query['state'] : throw new LogicException('A trip carries its state.');
}

test('a service connected by a trip to its site sends the user there, and the way back connects it on the import page', function () {
    app(ConnectionRegistry::class)->register(FakeRedirectService::CODE, FakeRedirectService::class);
    FakeConnectionImportSource::$service = FakeRedirectService::CODE;
    $user = User::factory()->create();

    $state = startTrip($user);

    expect(strlen($state))->toBeGreaterThanOrEqual(32);

    $this->get(route('settings.connections.callback', [FakeRedirectService::CODE, 'state' => $state, 'code' => 'code-1']))
        ->assertRedirect(route('settings.import.index', ['source' => FakeConnectionImportSource::CODE]));

    $connection = $user->serviceConnections()->sole();

    expect($connection->service)->toBe(FakeRedirectService::CODE)
        ->and($connection->credential('access_token'))->toBe('token-for-code-1')
        ->and($connection->account_label)->toBe('alex')
        // The address the service was told, from APP_URL, both ways.
        ->and(FakeRedirectService::$callbacks)->toBe([
            PublicUrl::to(route('settings.connections.callback', FakeRedirectService::CODE, absolute: false)),
            PublicUrl::to(route('settings.connections.callback', FakeRedirectService::CODE, absolute: false)),
        ]);

    $this->get(route('settings.import.index', ['source' => FakeConnectionImportSource::CODE]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selected', FakeConnectionImportSource::CODE)
            ->where('sources', fn ($sources) => collect($sources)->contains(fn ($source) => $source['code'] === FakeConnectionImportSource::CODE
                && $source['connect']['method'] === ConnectMethod::Redirect->value)));
});

test('a way back without the attempt\'s state leaves the attempt standing; the right one connects once', function () {
    app(ConnectionRegistry::class)->register(FakeRedirectService::CODE, FakeRedirectService::class);
    $user = User::factory()->create();
    $state = startTrip($user);

    $this->get(route('settings.connections.callback', [FakeRedirectService::CODE, 'state' => 'someone-elses', 'code' => 'code-1']))
        ->assertRedirect(route('settings.import.index'))
        ->assertSessionHasErrors([RedirectAttempts::errorKey(FakeRedirectService::CODE) => 'The connection attempt expired or was already used. Connect again.']);

    expect($user->serviceConnections()->exists())->toBeFalse();

    $this->get(route('settings.connections.callback', [FakeRedirectService::CODE, 'state' => $state, 'code' => 'code-1']))
        ->assertSessionHasNoErrors();

    // Spent: the same answer again connects nothing more.
    $this->get(route('settings.connections.callback', [FakeRedirectService::CODE, 'state' => $state, 'code' => 'code-2']))
        ->assertSessionHasErrors(RedirectAttempts::errorKey(FakeRedirectService::CODE));

    expect($user->serviceConnections()->sole()->credential('access_token'))->toBe('token-for-code-1');
});

test('the way back is only for services connected by a trip, and only for the one who started it', function () {
    app(ConnectionRegistry::class)->register(FakeRedirectService::CODE, FakeRedirectService::class);

    $this->actingAs(User::factory()->create())
        ->get(route('settings.connections.callback', [FakeDeviceService::CODE, 'code' => 'x']))
        ->assertNotFound();

    $state = startTrip(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->get(route('settings.connections.callback', [FakeRedirectService::CODE, 'state' => $state, 'code' => 'code-1']))
        ->assertSessionHasErrors(RedirectAttempts::errorKey(FakeRedirectService::CODE));

    expect(ServiceConnection::query()->count())->toBe(0);
});

test('a source the import page does not know is no preselection', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.import.index', ['source' => 'nothing-like-it']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('selected', null));
});
