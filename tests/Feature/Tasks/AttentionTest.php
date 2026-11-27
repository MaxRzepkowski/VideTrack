<?php

use App\Enums\TaskRunStatus;
use App\Integrations\Apprise\AppriseChannel;
use App\Integrations\Apprise\AppriseReach;
use App\Jobs\CleanupExpiredData;
use App\Models\MaintenanceCheckState;
use App\Models\NotificationChannel;
use App\Models\TaskRun;
use App\Models\User;
use App\Tasks\Attention\AttentionRegistry;
use App\Tasks\Attention\Data\PageVisit;
use App\Tasks\Attention\RecentlyFailedJobs;
use App\Tasks\Attention\StalledProcesses;
use App\Tasks\Liveness\BeatingProcess;
use App\Tasks\Liveness\Heartbeat;
use App\Tasks\Maintenance\CheckLevel;
use App\Tasks\Maintenance\Checks\ProviderProbe;
use App\Tasks\Maintenance\Data\CheckLine;
use App\Tasks\Maintenance\MaintenanceChecks;
use App\Tasks\RunFailure;
use App\Tasks\TaskName;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/** @return list<string> the titles of what needs the admin now, on a page opened at $root */
function attentionTitles(string $root = 'http://localhost'): array
{
    return array_map(fn ($item) => $item->title, app(AttentionRegistry::class)->items(new PageVisit($root)));
}

/** A job of the database queue, ready since $readySeconds ago, taken $reservedSeconds ago if at all. */
function queuedJob(int $readySeconds, ?int $reservedSeconds = null): void
{
    DB::table(config()->string('queue.connections.database.table'))->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => $reservedSeconds === null ? 0 : 1,
        'reserved_at' => $reservedSeconds === null ? null : now()->subSeconds($reservedSeconds)->getTimestamp(),
        'available_at' => now()->subSeconds($readySeconds)->getTimestamp(),
        'created_at' => now()->subSeconds($readySeconds)->getTimestamp(),
    ]);
}

test('the worker leaves a sign of life on every loop, written once a minute', function () {
    event(new Looping('database', 'default'));
    $first = app(Heartbeat::class)->lastBeat(BeatingProcess::Worker);

    $this->travel(Heartbeat::REWRITE_AFTER_SECONDS - 1)->seconds();
    event(new Looping('database', 'default'));

    expect(app(Heartbeat::class)->lastBeat(BeatingProcess::Worker)->eq($first))->toBeTrue();

    $this->travel(1)->seconds();
    event(new Looping('database', 'default'));

    expect(app(Heartbeat::class)->lastBeat(BeatingProcess::Worker)->eq(now()))->toBeTrue();
});

test('the worker leaves a sign of life when it finishes a job', function () {
    CleanupExpiredData::queueTask();

    expect(app(Heartbeat::class)->lastBeat(BeatingProcess::Worker)?->eq(now()))->toBeTrue();
});

test('the scheduler leaves a sign of life every minute', function () {
    $this->artisan('schedule:run')->assertSuccessful();

    expect(app(Heartbeat::class)->lastBeat(BeatingProcess::Scheduler)?->eq(now()))->toBeTrue();
});

test('a scheduler gone quiet needs the admin', function () {
    app(Heartbeat::class)->beat(BeatingProcess::Scheduler);
    TaskRun::queueTask(TaskName::Cleanup->value);
    $this->travel(StalledProcesses::SCHEDULER_QUIET_SECONDS - 1)->seconds();

    expect(attentionTitles())->not->toContain(__('tasks.the_scheduler_is_not_running'));

    $this->travel(1)->seconds();

    expect(attentionTitles())->toContain(__('tasks.the_scheduler_is_not_running'));
});

test('a scheduler that never ran needs the admin once the instance has been up a while', function () {
    expect(attentionTitles())->not->toContain(__('tasks.the_scheduler_is_not_running'));

    TaskRun::queueTask(TaskName::Cleanup->value);
    $this->travel(StalledProcesses::SCHEDULER_QUIET_SECONDS)->seconds();

    expect(attentionTitles())->toContain(__('tasks.the_scheduler_is_not_running'));
});

test('a quiet worker needs the admin once a job has waited, unless it is at a long job', function () {
    config(['queue.default' => 'database']);

    expect(attentionTitles())->not->toContain(__('tasks.the_worker_is_not_running'));

    queuedJob(StalledProcesses::WORKER_QUIET_SECONDS);

    expect(attentionTitles())->toContain(__('tasks.the_worker_is_not_running'));

    queuedJob(StalledProcesses::WORKER_QUIET_SECONDS, reservedSeconds: config()->integer('queue.connections.database.retry_after') - 1);

    expect(attentionTitles())->not->toContain(__('tasks.the_worker_is_not_running'));
});

test('a worker that still loops needs nobody, however long a job waits', function () {
    config(['queue.default' => 'database']);
    queuedJob(StalledProcesses::WORKER_QUIET_SECONDS);
    app(Heartbeat::class)->beat(BeatingProcess::Worker);

    expect(attentionTitles())->not->toContain(__('tasks.the_worker_is_not_running'));
});

test('jobs that failed within a day are a warning, left off the home page', function () {
    $table = config()->string('queue.failed.table');
    DB::table($table)->insert(['uuid' => 'job-of-alex', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'Broke.', 'failed_at' => now()->subSeconds(RecentlyFailedJobs::WITHIN_SECONDS - 1)]);
    DB::table($table)->insert(['uuid' => 'old-job-of-alex', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'Broke.', 'failed_at' => now()->subSeconds(RecentlyFailedJobs::WITHIN_SECONDS + 1)]);

    $item = collect(app(AttentionRegistry::class)->items(new PageVisit('http://localhost')))->firstWhere('title', trans_choice('tasks.count_jobs_failed_within_a_day', 1, ['count' => 1]));

    expect($item?->level)->toBe(CheckLevel::Warning)
        ->and(app(AttentionRegistry::class)->problemCount(new PageVisit('http://localhost')))->toBe(0);
});

test('a missing storage link needs the admin', function () {
    config(['filesystems.links' => [scratchDirectory('public-').'/storage' => storage_path('app/public')]]);

    expect(attentionTitles())->toContain(__('tasks.the_storage_link_is_missing'));
});

test('an APP_URL naming this computer warns only when the page is open from elsewhere', function (string $appUrl, string $visited, bool $warns) {
    config(['app.url' => $appUrl]);

    expect(in_array(__('tasks.app_url_names_this_computer'), attentionTitles($visited), true))->toBe($warns);
})->with([
    'localhost, opened from the network' => ['http://localhost:8080', 'http://192.168.1.50:8080', true],
    'the loopback address, opened by a name' => ['http://127.0.0.1', 'https://tracker.example.com', true],
    'localhost, opened here' => ['http://localhost:8080', 'http://localhost:8080', false],
    'the network address, opened here through localhost' => ['http://192.168.1.50:8080', 'http://localhost:8080', false],
    'a name, opened by another' => ['https://tracker.example.com', 'https://other.example.com', false],
]);

test('the overview tells the APP_URL against the address the page was opened at', function () {
    config(['app.url' => 'http://localhost']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(str_replace('http://localhost', 'http://192.168.1.50', route('settings.instance.index')))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attention.0.level', CheckLevel::Warning->value)
            ->where('attention.0.title', __('tasks.app_url_names_this_computer')));
});

test('a check whose run broke needs the admin', function () {
    MaintenanceCheckState::factory()->create(['code' => ProviderProbe::CODE, 'status' => TaskRunStatus::Failure, 'error' => RunFailure::unexpected()->stored()]);

    expect(attentionTitles())->toContain(__('tasks.check_broke', ['check' => __('tasks.provider_health')]));
});

test('Apprise is checked once a channel sends through it, and a silent container needs the admin', function () {
    config(['tracker.apprise.url' => 'http://apprise.test:8000']);
    $check = app(AppriseReach::class);
    $silent = __('tasks.apprise_is_not_answering', ['url' => 'http://apprise.test:8000']);

    expect($check->applies())->toBeFalse();

    $channel = NotificationChannel::factory()->create(['driver' => AppriseChannel::CODE, 'kind' => 'json', 'label' => 'Apprise', 'config' => [], 'enabled' => false]);

    expect($check->applies())->toBeFalse();

    $channel->update(['enabled' => true]);
    Http::fake(['apprise.test:8000/*' => Http::response([], 500)]);

    expect($check->applies())->toBeTrue();

    app(MaintenanceChecks::class)->perform('run-of-alex', [AppriseReach::CODE]);

    $line = CheckLine::fromStored(MaintenanceCheckState::query()->where('code', AppriseReach::CODE)->sole()->result[0]);

    expect($line->level)->toBe(CheckLevel::Problem)
        ->and(attentionTitles())->toContain($silent);

    Http::swap(new Factory);
    Http::fake(['apprise.test:8000/*' => Http::response(['schemas' => []])]);
    app(MaintenanceChecks::class)->perform('run-of-alex', [AppriseReach::CODE]);

    expect(attentionTitles())->not->toContain($silent);
});
