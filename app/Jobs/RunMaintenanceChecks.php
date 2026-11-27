<?php

namespace App\Jobs;

use App\Tasks\Maintenance\MaintenanceChecks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * One run of a window's maintenance checks, one after another.
 */
class RunMaintenanceChecks implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** A whole window's checks in one run; each check's lock lapses with it. */
    public const TIMEOUT_SECONDS = 600;

    public int $timeout = self::TIMEOUT_SECONDS;

    // Its window runs the checks again; a retry would only run them twice.
    public int $tries = 1;

    /** Marks the checks this run takes, so a run that dies ends only its own. */
    public readonly string $runId;

    /**
     * @param  list<string>  $codes
     */
    public function __construct(public readonly array $codes)
    {
        $this->runId = (string) Str::uuid();
    }

    public function handle(MaintenanceChecks $checks): void
    {
        $checks->perform($this->runId, $this->codes);
    }

    public function failed(Throwable $exception): void
    {
        MaintenanceChecks::abandon($this->runId);
    }
}
