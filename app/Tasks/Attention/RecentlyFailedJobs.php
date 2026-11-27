<?php

namespace App\Tasks\Attention;

use App\Tasks\Attention\Contracts\AttentionSource;
use App\Tasks\Attention\Data\AttentionItem;
use App\Tasks\Attention\Data\PageVisit;
use App\Tasks\Data\TaskLine;
use App\Tasks\Data\WordedLine;
use App\Tasks\Maintenance\CheckLevel;
use Illuminate\Support\Facades\DB;

/**
 * Background jobs that gave up within the last day: each said why in its
 * own run, where it has one, and in the worker's log. A warning: one may
 * have been a service down for a moment.
 */
final readonly class RecentlyFailedJobs implements AttentionSource
{
    /** How far back a failed job still needs a look. */
    public const WITHIN_SECONDS = 86400;

    public function items(PageVisit $visit): array
    {
        $count = DB::table(config()->string('queue.failed.table'))->where('failed_at', '>=', now()->subSeconds(self::WITHIN_SECONDS))->count();

        if ($count === 0) {
            return [];
        }

        return [new AttentionItem(
            CheckLevel::Warning,
            trans_choice('tasks.count_jobs_failed_within_a_day', $count, ['count' => $count]),
            [WordedLine::of(new TaskLine('tasks.see_the_failed_jobs'))],
            null,
        )];
    }
}
