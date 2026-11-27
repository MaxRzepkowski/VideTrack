<?php

namespace App\Tasks\Maintenance\Contracts;

use App\Tasks\Maintenance\CheckReport;
use App\Tasks\Maintenance\MaintenanceWindow;

/**
 * One piece of the instance's upkeep: it looks at something, does what it is
 * for and tells its report. It knows nothing of the queue, the history or the
 * schedule. It may clear technical data and queue refreshes; what a user
 * wrote in their library it never changes.
 */
interface MaintenanceCheck
{
    /** What the task history calls it, in the request's language. */
    public function label(): string;

    public function window(): MaintenanceWindow;

    /**
     * Where it runs within its window, lowest first: clearing out comes
     * before the database upkeep, the health report last.
     */
    public function order(): int;

    /**
     * Whether this instance has what the check looks after (its database
     * engine, its module). One that does not is never run and never shown.
     */
    public function applies(): bool;

    public function run(CheckReport $report): void;
}
