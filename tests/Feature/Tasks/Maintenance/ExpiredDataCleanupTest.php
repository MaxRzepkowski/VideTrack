<?php

use App\Models\MaintenanceCheckState;
use App\Models\TaskRun;
use App\Tasks\Maintenance\Checks\ExpiredDataCleanup;
use App\Tasks\Maintenance\Data\CheckLine;
use App\Tasks\Maintenance\ExpiredKind;
use App\Tasks\Maintenance\MaintenanceChecks;
use App\Tasks\Maintenance\MaintenanceRegistry;
use App\Tasks\Maintenance\MaintenanceWindow;
use App\Tasks\TaskName;
use Illuminate\Support\Facades\DB;

function cleanExpired(): MaintenanceCheckState
{
    app(MaintenanceChecks::class)->perform('run-of-alex', [ExpiredDataCleanup::CODE]);

    return MaintenanceCheckState::query()->where('code', ExpiredDataCleanup::CODE)->sole();
}

test('expired rows of the database cache go, whichever store the cache uses now', function (string $store) {
    config(['cache.default' => $store]);
    $table = config()->string('cache.stores.database.table');
    DB::table($table)->insert([
        ['key' => 'expired-of-alex', 'value' => 'x', 'expiration' => now()->subMinute()->getTimestamp()],
        ['key' => 'fresh-of-alex', 'value' => 'x', 'expiration' => now()->addMinute()->getTimestamp()],
    ]);

    $state = cleanExpired();
    $left = DB::table($table)->pluck('key')->all();

    expect($left)->toContain('fresh-of-alex')
        ->and($left)->not->toContain('expired-of-alex')
        ->and(collect(CheckLine::listFromStored($state->result))->firstWhere('subject', ExpiredKind::Cache->value)->state->wording())
        ->toBe(__('tasks.removed_count', ['count' => 1]));
})->with(['the database' => ['database'], 'files, after it moved off the database' => ['file']]);

test('old task runs go, and the run that removed something says so in the history', function () {
    $old = TaskRun::queueTask(TaskName::RefreshCollections->value);
    $old->forceFill(['created_at' => now()->subDays(config()->integer('tracker.retention.task_runs_days') + 1)])->save();
    $fresh = TaskRun::queueTask(TaskName::RefreshCollections->value);

    cleanExpired();

    expect(TaskRun::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(TaskRun::query()->whereKey($fresh->id)->exists())->toBeTrue()
        ->and(TaskRun::query()->where('name', MaintenanceChecks::historyName(ExpiredDataCleanup::CODE))->sole()->summary)
        ->toBe(__('tasks.removed_expired_data', ['runs' => 1, 'cache' => 0, 'jobs' => 0, 'notifications' => 0, 'deliveries' => 0, 'views' => 0]));
});

test('a quiet night leaves no history', function () {
    cleanExpired();

    expect(TaskRun::query()->count())->toBe(0);
});

test('the clean-up runs with the other night checks', function () {
    expect(app(MaintenanceRegistry::class)->applicable(MaintenanceWindow::Nightly))->toHaveKey(ExpiredDataCleanup::CODE);
});
