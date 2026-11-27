<?php

namespace App\Tasks\Maintenance;

/** What the expired-data clean-up prunes, in the order it does. */
enum ExpiredKind: string
{
    case TaskRuns = 'task-runs';
    case Cache = 'cache';
    case FailedJobs = 'failed-jobs';
    case Notifications = 'notifications';
    case WebhookDeliveries = 'webhook-deliveries';
    case RecentViews = 'recent-views';

    /** A translation key: what the clean-up's row calls the kind. */
    public function label(): string
    {
        return match ($this) {
            self::TaskRuns => 'tasks.expired_task_runs',
            self::Cache => 'tasks.expired_cache',
            self::FailedJobs => 'tasks.expired_failed_jobs',
            self::Notifications => 'tasks.expired_notifications',
            self::WebhookDeliveries => 'tasks.expired_webhook_deliveries',
            self::RecentViews => 'tasks.expired_recent_views',
        };
    }
}
