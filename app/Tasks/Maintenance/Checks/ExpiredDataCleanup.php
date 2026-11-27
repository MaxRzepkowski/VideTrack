<?php

namespace App\Tasks\Maintenance\Checks;

use App\Library\RecentViews;
use App\Models\TaskRun;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Numbers;
use App\Tasks\Data\TaskLine;
use App\Tasks\Maintenance\CheckLevel;
use App\Tasks\Maintenance\CheckReport;
use App\Tasks\Maintenance\Contracts\MaintenanceCheck;
use App\Tasks\Maintenance\Data\CheckLine;
use App\Tasks\Maintenance\ExpiredKind;
use App\Tasks\Maintenance\MaintenanceWindow;
use Illuminate\Support\Facades\DB;

/**
 * Nightly housekeeping, by `tracker.retention`: old task runs and
 * notifications, expired rows of the database cache, stale failed jobs,
 * webhook deliveries past their age or their cap, and the tail of each
 * user's browsing history.
 */
final readonly class ExpiredDataCleanup implements MaintenanceCheck
{
    public const CODE = 'expired-data';

    public function __construct(private RecentViews $recentViews) {}

    public function label(): string
    {
        return __('tasks.expired_data');
    }

    public function window(): MaintenanceWindow
    {
        return MaintenanceWindow::Nightly;
    }

    // After the orphans: what they leave in the history is pruned with the rest.
    public function order(): int
    {
        return 20;
    }

    public function applies(): bool
    {
        return true;
    }

    public function run(CheckReport $report): void
    {
        $removed = [
            ExpiredKind::TaskRuns->value => Numbers::int(TaskRun::query()
                ->where('created_at', '<', now()->subDays(config()->integer('tracker.retention.task_runs_days')))
                ->delete()) ?? 0,
            // Whichever store the cache uses now: an instance that moved it off
            // the database leaves the expired rows behind, and nothing reads them.
            ExpiredKind::Cache->value => DB::table(config()->string('cache.stores.database.table', 'cache'))
                ->where('expiration', '<', now()->getTimestamp())
                ->delete(),
            ExpiredKind::FailedJobs->value => DB::table(config()->string('queue.failed.table'))
                ->where('failed_at', '<', now()->subDays(config()->integer('tracker.retention.failed_jobs_days')))
                ->delete(),
            // Notifications are a feed, not an archive — the activity pages hold
            // the durable history.
            ExpiredKind::Notifications->value => DB::table('notifications')
                ->where('created_at', '<', now()->subDays(config()->integer('tracker.retention.notifications_days')))
                ->delete(),
            ExpiredKind::WebhookDeliveries->value => $this->pruneWebhookDeliveries(),
            // Trimmed here, not on every page view: a GET should not delete.
            ExpiredKind::RecentViews->value => $this->recentViews->prune(),
        ];

        foreach (ExpiredKind::cases() as $kind) {
            $state = $removed[$kind->value] === 0
                ? new TaskLine('tasks.removed_none')
                : new TaskLine('tasks.removed_count', ['count' => $removed[$kind->value]]);

            $report->add(new CheckLine(CheckLevel::Ok, $state, subject: $kind->value, label: new TaskLine($kind->label()), state: $state));
        }

        if (array_sum($removed) > 0) {
            $report->note('tasks.removed_expired_data', [
                'runs' => $removed[ExpiredKind::TaskRuns->value],
                'cache' => $removed[ExpiredKind::Cache->value],
                'jobs' => $removed[ExpiredKind::FailedJobs->value],
                'notifications' => $removed[ExpiredKind::Notifications->value],
                'deliveries' => $removed[ExpiredKind::WebhookDeliveries->value],
                'views' => $removed[ExpiredKind::RecentViews->value],
            ]);
        }
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
