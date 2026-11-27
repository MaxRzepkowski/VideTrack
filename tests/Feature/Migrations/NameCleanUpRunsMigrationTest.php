<?php

use App\Models\TaskRun;
use App\Tasks\Maintenance\Checks\ExpiredDataCleanup;
use App\Tasks\Maintenance\MaintenanceChecks;
use App\Tasks\TaskName;
use Illuminate\Database\Migrations\Migration;

test('past clean-up runs take the name of its check, and other runs keep theirs', function () {
    $cleanup = TaskRun::queueTask('cleanup');
    $other = TaskRun::queueTask(TaskName::RefreshCollections->value);

    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_27_000004_name_clean_up_runs_after_their_check.php');
    $migration->up();

    expect($cleanup->refresh()->name)->toBe(MaintenanceChecks::historyName(ExpiredDataCleanup::CODE))
        ->and($other->refresh()->name)->toBe(TaskName::RefreshCollections->value);
});
