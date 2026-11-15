<?php

use App\Enums\TaskRunStatus;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Import\ImportSourceRegistry;
use App\Jobs\Middleware\OneRunAtATime;
use App\Jobs\QueueAutoImports;
use App\Jobs\RunImport;
use App\Models\ServiceConnection;
use App\Models\TaskRun;
use App\Models\User;
use App\Tasks\ScheduledTasks;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeConnectionImportSource;
use Tests\Fakes\FakeDeviceService;

beforeEach(function () {
    app(ConnectionRegistry::class)->register(FakeDeviceService::CODE, FakeDeviceService::class);
    app(ImportSourceRegistry::class)->register(FakeConnectionImportSource::CODE, FakeConnectionImportSource::class);
});

function autoConnection(array $attributes = []): ServiceConnection
{
    return ServiceConnection::factory()->create([
        'user_id' => User::factory()->create()->id,
        'service' => FakeDeviceService::CODE,
        'credentials' => ['access_token' => 'x'],
        'status' => ConnectionStatus::Active,
        'auto_import' => true,
        ...$attributes,
    ]);
}

/**
 * @return list<int> the connection ids an auto-import pass queued runs for
 */
function runAutoImport(): array
{
    Queue::fake();

    $taskRun = TaskRun::queueTask('auto-import');
    app()->call([new QueueAutoImports($taskRun), 'handle']);

    return Queue::pushed(RunImport::class)
        ->map(fn (RunImport $job) => (fn () => $this->input)->call($job)->connectionId)
        ->values()
        ->all();
}

test('auto-import is an hourly scheduled task', function () {
    $task = collect(ScheduledTasks::all())->firstWhere('name', 'auto-import');

    expect($task)->not->toBeNull()
        ->and($task->cron)->toBe('15 * * * *');
});

test('a due connection with auto-import on gets a run', function () {
    $never = autoConnection();
    $due = autoConnection(['last_imported_at' => now()->subHours(2)]);

    expect(runAutoImport())->toEqualCanonicalizing([$never->id, $due->id])
        ->and(TaskRun::query()->where('name', 'auto-import')->sole()->summary)->toBe(__('tasks.summary_auto_imports', ['count' => 2]));
});

test('the source sets the pace', function () {
    // The fake source runs hourly; a few minutes of slack keep it on the hour.
    $recent = autoConnection(['last_imported_at' => now()->subMinutes(20)]);
    $almost = autoConnection(['last_imported_at' => now()->subMinutes(57)]);

    expect(runAutoImport())->toBe([$almost->id]);
});

test('switched off, expired or already running connections are left alone', function () {
    autoConnection(['auto_import' => false]);
    autoConnection(['status' => ConnectionStatus::Expired]);
    $busy = autoConnection();
    TaskRun::query()->create([
        'user_id' => $busy->user_id,
        'name' => 'import-'.FakeConnectionImportSource::CODE,
        'status' => TaskRunStatus::Started,
        'queued_at' => now(),
    ]);

    expect(runAutoImport())->toBe([]);
});

test('a run a killed worker left started does not block the connection for good', function () {
    $stuck = autoConnection();
    TaskRun::query()->create([
        'user_id' => $stuck->user_id,
        'name' => 'import-'.FakeConnectionImportSource::CODE,
        'status' => TaskRunStatus::Started,
        'queued_at' => now()->subSeconds(2 * RunImport::TIMEOUT_SECONDS + 60),
    ]);

    expect(runAutoImport())->toBe([$stuck->id]);
});

test('a task job keeps one run per task and user, and a failure closes its row', function () {
    $taskRun = TaskRun::queueTask('auto-import');
    $job = new QueueAutoImports($taskRun);

    $overlap = $job->middleware()[0];

    expect($overlap)->toBeInstanceOf(OneRunAtATime::class)
        ->and($overlap->key)->toBe('auto-import:')
        ->and($overlap->expiresAfterSeconds)->toBe($job->timeout);

    $job->failed(new RuntimeException('worker died'));

    expect($taskRun->refresh()->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe(__(QueueAutoImports::UNEXPECTED_FAILURE));
});

test('the real paces: Simkl daily, Tracearr hourly', function () {
    config(['tracker.simkl.client_id' => 'simkl-client']);
    app()->forgetInstance(ImportSourceRegistry::class);
    $sources = app(ImportSourceRegistry::class);

    expect($sources->get('simkl')->autoImportMinutes())->toBe(1440)
        ->and($sources->get('tracearr')->autoImportMinutes())->toBe(60);
});
