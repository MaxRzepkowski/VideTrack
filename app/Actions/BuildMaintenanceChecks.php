<?php

namespace App\Actions;

use App\Models\MaintenanceCheckState;
use App\Tasks\Data\MaintenanceCheckEntry;
use App\Tasks\Data\MaintenanceCheckLine;
use App\Tasks\Data\TaskLine;
use App\Tasks\Data\WordedLine;
use App\Tasks\Maintenance\Contracts\MaintenanceCheck;
use App\Tasks\Maintenance\Data\CheckLine;
use App\Tasks\Maintenance\MaintenanceRegistry;

/**
 * The instance's maintenance checks in the order they run, each with where
 * it stands — the rows of the Instance page's Maintenance tab, worded in the
 * viewer's language.
 */
final readonly class BuildMaintenanceChecks
{
    public function __construct(private MaintenanceRegistry $registry) {}

    /**
     * @return list<MaintenanceCheckEntry>
     */
    public function execute(): array
    {
        $checks = $this->registry->applicable();
        $states = MaintenanceCheckState::query()
            ->whereIn('code', array_keys($checks))
            ->get()
            ->keyBy(fn (MaintenanceCheckState $state): string => $state->code);

        $entries = [];

        foreach ($checks as $code => $check) {
            $entries[] = $this->entry($code, $check, $states->get($code));
        }

        return $entries;
    }

    private function entry(string $code, MaintenanceCheck $check, ?MaintenanceCheckState $state): MaintenanceCheckEntry
    {
        $lines = [];

        foreach (CheckLine::listFromStored($state?->result) as $line) {
            $lines[] = new MaintenanceCheckLine($line->level, WordedLine::of($line->line), array_map(WordedLine::of(...), $line->details), $line->label, $line->state === null ? null : WordedLine::of($line->state));
        }

        $error = TaskLine::fromStored($state?->error);

        return new MaintenanceCheckEntry(
            code: $code,
            label: $check->label(),
            frequency: $check->window()->frequency(),
            status: $state?->status,
            lines: $lines,
            error: $error?->wording(),
            startedAt: $state?->started_at?->toIso8601String(),
            checkedAt: $state?->checked_at?->toIso8601String(),
        );
    }
}
