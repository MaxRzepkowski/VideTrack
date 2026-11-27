<?php

use App\Tasks\Maintenance\MaintenanceWindow;
use Illuminate\Support\Carbon;

test('a window was last due at its own minute', function (MaintenanceWindow $window, string $now, string $due) {
    expect($window->previousRunAt(Carbon::parse($now))->toDateTimeString())->toBe($due);
})->with([
    'the quarter hours, off the round minute' => [MaintenanceWindow::Frequent, '2026-10-10 12:30:00', '2026-10-10 12:22:00'],
    'the night before' => [MaintenanceWindow::Nightly, '2026-10-10 01:00:00', '2026-10-09 02:30:00'],
    'the last Sunday' => [MaintenanceWindow::Weekly, '2026-10-10 12:00:00', '2026-10-04 02:00:00'],
]);
