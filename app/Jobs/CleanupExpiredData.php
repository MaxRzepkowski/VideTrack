<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsAsTask;
use App\Library\RecentViews;
use App\Models\TaskRun;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Numbers;
use App\Tasks\TaskName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Daily housekeeping, by `tracker.retention`: old task_runs and notifications, expired
 * database-cache rows, stale failed queue jobs and the tail of each user's
 * browsing history.
 */
class CleanupExpiredData implements ShouldQueue
{
    use Dispatchable, Queueable, RunsAsTask;

    // The worker's default, stated: the overlap lock lapses with it.
    public int $timeout = 60;

    public int $maxExceptions = self::BREAKS_ALLOWED;

    public function __construct(private readonly TaskRun $taskRun) {}

    public static function queueTask(): void
    {
        self::dispatch(TaskRun::queueTask(TaskName::Cleanup->value));
    }

    public function handle(RecentViews $recentViews): void
    {
        $this->taskRun->markStarted();

        $taskRuns = Numbers::int(TaskRun::query()
            ->where('created_at', '<', now()->subDays(config()->integer('tracker.retention.task_runs_days')))
            ->delete()) ?? 0;

        // Whichever store the cache uses now: an instance that moved it off
        // the database leaves the expired rows behind, and nothing reads them.
        $cacheRows = DB::table(config()->string('cache.stores.database.table', 'cache'))
            ->where('expiration', '<', now()->getTimestamp())
            ->delete();

        $failedJobs = DB::table('failed_jobs')
            ->where('failed_at', '<', now()->subDays(config()->integer('tracker.retention.failed_jobs_days')))
            ->delete();

        // Notifications are a feed, not an archive — the activity pages hold
        // the durable history.
        $notifications = DB::table('notifications')
            ->where('created_at', '<', now()->subDays(config()->integer('tracker.retention.notifications_days')))
            ->delete();

        $deliveries = $this->pruneWebhookDeliveries();

        // Trimmed here, not on every page view: a GET should not delete.
        $recent = $recentViews->prune();

        $this->taskRun->markSuccess(
            "Removed {$taskRuns} old task runs, {$cacheRows} expired cache entries, {$failedJobs} old failed jobs, {$notifications} old notifications, {$deliveries} old webhook deliveries, {$recent} old recently viewed entries.",
        );
    }

    /**
     * Two ceilings, because a webhook log fails two ways: it goes stale, and
     * a chatty server buries yesterday under today. Age prunes the first,
     * a per-endpoint cap the second.
     */
    private function pruneWebhookDeliveries(): int
    {
        $days = config()->integer('tracker.retention.webhook_deliveries_days');
        $rows = config()->integer('tracker.retention.webhook_deliveries_per_endpoint');

        $deleted = Numbers::int(WebhookDelivery::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete()) ?? 0;

        foreach (WebhookEndpoint::query()->pluck('id') as $endpointId) {
            $cutoff = WebhookDelivery::query()
                ->where('webhook_endpoint_id', $endpointId)
                ->orderByDesc('id')
                ->skip($rows)
                ->take(1)
                ->value('id');

            if ($cutoff === null) {
                continue;
            }

            $deleted += Numbers::int(WebhookDelivery::query()
                ->where('webhook_endpoint_id', $endpointId)
                ->where('id', '<=', $cutoff)
                ->delete()) ?? 0;
        }

        return $deleted;
    }
}
