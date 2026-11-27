<?php

use App\Enums\TaskRunStatus;
use App\Jobs\RefreshCollections;
use App\Models\TaskRun;
use App\Models\User;
use App\Tasks\ScheduledTasks;
use App\Tasks\TaskName;
use App\Tasks\TaskRunHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('the tasks page renders', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/tasks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Tasks'));
});

test('guests are redirected to login', function () {
    $this->get('/settings/tasks')->assertRedirect('/login');
});

test('system runs are visible to admins only', function () {
    $run = TaskRun::queueTask('refresh-catalog');
    $run->markStarted();
    $run->markSuccess('Refreshed 2 of 2 items.');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('settings.instance.tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/instance/Tasks')
            ->has('runs', 1)
            ->where('runs.0.name', 'refresh-catalog')
            ->where('runs.0.status', 'success')
            ->where('runs.0.summary', 'Refreshed 2 of 2 items.'));

    $this->actingAs(User::factory()->create())
        ->get('/settings/tasks')
        ->assertInertia(fn (Assert $page) => $page->has('runs', 0));
});

test('users see their own runs but not other users runs', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    TaskRun::queueTask('import', $owner->id);
    TaskRun::queueTask('import', $other->id);

    $this->actingAs($owner)
        ->get('/settings/tasks')
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 1)
            ->where('runs.0.name', 'import'));
});

test('only the most recent runs are listed, newest first', function () {
    $user = User::factory()->create();

    $runs = TaskRunHistory::RUN_LIMIT + 5;

    foreach (range(1, $runs) as $i) {
        TaskRun::queueTask('import', $user->id)
            ->forceFill(['queued_at' => now()->subMinutes($runs - $i)])
            ->save();
    }

    $newest = TaskRun::query()->latest('queued_at')->value('id');

    $this->actingAs($user)
        ->get('/settings/tasks')
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', TaskRunHistory::RUN_LIMIT)
            ->where('runs.0.id', $newest));
});

test("each scheduled task shows its newest system run, never a user's", function () {
    TaskRun::query()->create(['name' => 'refresh-catalog', 'status' => TaskRunStatus::Failure, 'queued_at' => now()->subDay()]);
    TaskRun::query()->create(['name' => 'refresh-catalog', 'status' => TaskRunStatus::Success, 'queued_at' => now()->subHour()]);
    $admin = User::factory()->admin()->create();
    TaskRun::query()->create(['user_id' => $admin->id, 'name' => 'refresh-catalog', 'status' => TaskRunStatus::Failure, 'queued_at' => now()]);

    $this->actingAs($admin)
        ->get(route('settings.instance.tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('schedule', fn ($schedule) => collect($schedule)->firstWhere('name', 'refresh-catalog')['lastStatus'] === 'success'
                && collect($schedule)->firstWhere('name', 'refresh-entities')['lastStatus'] === null));
});

test('admins get the recurring schedule with next run, others do not', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('settings.instance.tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('schedule', count(ScheduledTasks::all()))
            ->where('schedule.0.name', 'refresh-catalog')
            ->where('schedule.0.label', 'Refresh saved titles')
            ->where('schedule.0.frequency', 'common.daily')
            ->has('schedule.0.nextRunAt'));

    $this->actingAs(User::factory()->create())
        ->get('/settings/tasks')
        ->assertInertia(fn (Assert $page) => $page->missing('schedule'));
});

test('failure runs expose their errors', function () {
    $admin = User::factory()->admin()->create();
    $run = TaskRun::queueTask(TaskName::RefreshCollections->value);
    $run->markStarted();
    $run->markFailure('Boom.', ['disk full']);

    $this->actingAs($admin)
        ->get(route('settings.instance.tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('runs.0.status', 'failure')
            ->where('runs.0.errors', ['disk full']));
});

test('an admin can run a scheduled task now', function () {
    Queue::fake();

    $this->actingAs(User::factory()->admin()->create())
        ->post('/settings/tasks/run', ['name' => 'refresh-catalog'])
        ->assertRedirect();

    expect(TaskRun::query()->where('name', 'refresh-catalog')->where('status', TaskRunStatus::Pending)->count())
        ->toBe(1);
});

test('run now is skipped when the task is already in flight', function () {
    Queue::fake();

    // A run already queued for this task.
    TaskRun::queueTask('refresh-catalog');

    $this->actingAs(User::factory()->admin()->create())
        ->post('/settings/tasks/run', ['name' => 'refresh-catalog'])
        ->assertRedirect();

    // No duplicate dispatched.
    expect(TaskRun::query()->where('name', 'refresh-catalog')->count())->toBe(1);
});

test('a non-admin cannot run tasks and unknown tasks are rejected', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post('/settings/tasks/run', ['name' => 'refresh-catalog'])
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->post('/settings/tasks/run', ['name' => 'not-a-task'])
        ->assertNotFound();

    expect(TaskRun::query()->count())->toBe(0);
});

test('the schedule reports each task last run and status', function () {
    $run = TaskRun::queueTask('refresh-catalog');
    $run->markStarted();
    $run->markSuccess('Refreshed 2 of 2 items.');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('settings.instance.tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('schedule.0.name', 'refresh-catalog')
            ->where('schedule.0.lastStatus', 'success')
            ->has('schedule.0.lastRunAt')
            // A task that never ran reports no last run.
            ->where('schedule.1.lastStatus', null)
            ->where('schedule.1.lastRunAt', null));
});

test('clearing removes finished runs but keeps in-flight ones', function () {
    $user = User::factory()->create();

    $done = TaskRun::queueTask('import', $user->id);
    $done->markSuccess('ok');
    $failed = TaskRun::queueTask('import', $user->id);
    $failed->markStarted();
    $failed->markFailure('boom');
    $active = TaskRun::queueTask('import', $user->id);
    $active->markStarted();

    $this->actingAs($user)->delete('/settings/tasks')->assertRedirect();

    expect(TaskRun::query()->pluck('id')->all())->toBe([$active->id]);
});

test('a non-admin clearing leaves system and other users runs untouched', function () {
    $user = User::factory()->create();

    $mine = TaskRun::queueTask('import', $user->id);
    $mine->markSuccess('ok');
    $system = TaskRun::queueTask(TaskName::RefreshCollections->value);
    $system->markSuccess('ok');
    $other = TaskRun::queueTask('import', User::factory()->create()->id);
    $other->markSuccess('ok');

    $this->actingAs($user)->delete('/settings/tasks');

    expect(TaskRun::query()->orderBy('id')->pluck('id')->all())->toBe([$system->id, $other->id]);
});

test('catch-up queues a task overdue since its last due time — once, not a backlog', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-07-18 09:00:00'));

    // A daily task (due 04:00) whose last run was six windows ago.
    TaskRun::queueTask('refresh-catalog')
        ->forceFill(['queued_at' => now()->subDays(6), 'status' => TaskRunStatus::Success])
        ->save();

    $this->artisan('tasks:catch-up')->assertSuccessful();

    // Exactly one fresh run — overdue by six windows still means a single
    // catch-up, never six.
    expect(TaskRun::query()->where('name', 'refresh-catalog')
        ->where('status', TaskRunStatus::Pending)->count())->toBe(1);
});

test('catch-up leaves a task that already ran in the current window alone', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-07-18 09:00:00'));

    // Already ran today at 05:00, after the 04:00 due time.
    TaskRun::queueTask('refresh-catalog')
        ->forceFill(['queued_at' => Carbon::parse('2026-07-18 05:00:00'), 'status' => TaskRunStatus::Success])
        ->save();

    $this->artisan('tasks:catch-up')->assertSuccessful();

    expect(TaskRun::query()->where('name', 'refresh-catalog')->count())->toBe(1);
});

test('a task run ends once, and a failure after it reported its end changes nothing', function () {
    $run = TaskRun::queueTask(TaskName::RefreshCollections->value);
    $run->markStarted();
    $run->markSuccess('Done.');

    expect(fn () => $run->markFailure('Late.'))->toThrow(LogicException::class)
        ->and(fn () => $run->markStarted())->toThrow(LogicException::class);

    (new RefreshCollections($run->refresh()))->failed(new RuntimeException('After the end.'));

    expect($run->refresh()->status)->toBe(TaskRunStatus::Success)
        ->and($run->summary)->toBe('Done.');
});

test('a retried run starts again without finishing first', function () {
    $run = TaskRun::queueTask(TaskName::RefreshCollections->value);
    $run->markStarted();
    $run->markStarted();

    expect($run->refresh()->status)->toBe(TaskRunStatus::Started);
});
