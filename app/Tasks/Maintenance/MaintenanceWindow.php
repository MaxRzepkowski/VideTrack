<?php

namespace App\Tasks\Maintenance;

use Cron\CronExpression;
use Illuminate\Support\Carbon;

/**
 * When the maintenance checks run: each check names one window, and a window
 * runs its checks one after another.
 */
enum MaintenanceWindow: string
{
    case Frequent = 'frequent';
    case Nightly = 'nightly';
    case Weekly = 'weekly';

    public function cron(): string
    {
        return match ($this) {
            // Off the round minutes the hourly digest and the trending
            // prefetch take.
            self::Frequent => '7-59/15 * * * *',
            // Clear of the night's catalog work and its clean-up.
            self::Nightly => '30 2 * * *',
            self::Weekly => '0 2 * * 0',
        };
    }

    /** The most recent time the window was due on or before $now. */
    public function previousRunAt(Carbon $now): Carbon
    {
        return Carbon::instance((new CronExpression($this->cron()))->getPreviousRunDate($now));
    }
}
