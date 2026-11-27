<?php

namespace App\Tasks\Attention;

use App\Models\MaintenanceCheckState;
use App\Tasks\Attention\Contracts\AttentionSource;
use App\Tasks\Attention\Data\AttentionItem;
use App\Tasks\Attention\Data\PageVisit;
use App\Tasks\Data\TaskLine;
use App\Tasks\Data\WordedLine;
use App\Tasks\Maintenance\CheckLevel;
use App\Tasks\Maintenance\Data\CheckLine;
use App\Tasks\Maintenance\MaintenanceRegistry;

/**
 * What the maintenance checks last found the admin has to fix: each line they
 * said is a problem, and each check whose run broke. A warning stays in the
 * check's row, where it passes on its own.
 */
final readonly class ProblemsFromChecks implements AttentionSource
{
    public function __construct(private MaintenanceRegistry $registry) {}

    public function items(PageVisit $visit): array
    {
        $checks = $this->registry->applicable();
        $items = [];

        $states = MaintenanceCheckState::query()->whereIn('code', array_keys($checks))->get();

        foreach ($states as $state) {
            $error = TaskLine::fromStored($state->error);

            if ($error !== null && ! $state->isInFlight()) {
                $items[] = new AttentionItem(
                    CheckLevel::Problem,
                    __('tasks.check_broke', ['check' => $checks[$state->code]->label()]),
                    [WordedLine::of($error)],
                    $state->checked_at?->toIso8601String(),
                );
            }

            foreach (CheckLine::listFromStored($state->result) as $line) {
                if ($line->level === CheckLevel::Problem) {
                    $items[] = new AttentionItem(CheckLevel::Problem, $line->line->wording(), array_map(WordedLine::of(...), $line->details), $line->since?->toIso8601String());
                }
            }
        }

        return $items;
    }
}
