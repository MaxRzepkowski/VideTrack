<?php

namespace App\Integrations\Apprise;

use App\Models\NotificationChannel;
use App\Tasks\Data\TaskLine;
use App\Tasks\Maintenance\CheckLevel;
use App\Tasks\Maintenance\CheckReport;
use App\Tasks\Maintenance\Contracts\MaintenanceCheck;
use App\Tasks\Maintenance\Data\CheckLine;
use App\Tasks\Maintenance\MaintenanceWindow;

/**
 * Whether the instance's apprise-api container answers, once somebody sends
 * through it (a channel switched on): a container that stopped loses every message of every Apprise
 * channel, and nothing else says so before a message is lost. Asks it for
 * its description, the one request it answers without sending anything.
 */
final readonly class AppriseReach implements MaintenanceCheck
{
    public const CODE = 'apprise-reach';

    public function __construct(private AppriseClient $apprise) {}

    public function label(): string
    {
        return __('tasks.apprise_reach');
    }

    public function window(): MaintenanceWindow
    {
        return MaintenanceWindow::Frequent;
    }

    public function order(): int
    {
        return 20;
    }

    public function applies(): bool
    {
        return filled(config('tracker.apprise.url')) && NotificationChannel::query()->where('driver', AppriseChannel::CODE)->where('enabled', true)->exists();
    }

    public function run(CheckReport $report): void
    {
        $previous = $report->previous(self::CODE);

        if ($this->apprise->details() !== null) {
            $report->add(new CheckLine(CheckLevel::Ok, new TaskLine('tasks.apprise_answers'), subject: self::CODE));

            return;
        }

        $report->add(new CheckLine(CheckLevel::Problem, new TaskLine('tasks.apprise_is_not_answering', ['url' => config()->string('tracker.apprise.url')]), [
            new TaskLine('tasks.apprise_messages_are_lost'),
            new TaskLine('tasks.start_the_apprise_container'),
        ], self::CODE, $previous?->level === CheckLevel::Problem ? $previous->since : now()->toImmutable()));
    }
}
