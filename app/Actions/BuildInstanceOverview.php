<?php

namespace App\Actions;

use App\Models\MaintenanceCheckState;
use App\Tasks\Attention\AttentionRegistry;
use App\Tasks\Attention\Data\AttentionItem;
use App\Tasks\Attention\Data\PageVisit;

/**
 * The Instance page's overview: what needs the admin, and when the checks
 * last looked — so an empty list reads as checked, not as never asked.
 */
final readonly class BuildInstanceOverview
{
    public function __construct(private AttentionRegistry $attention) {}

    /**
     * @return array{attention: list<AttentionItem>, checkedAt: string|null}
     */
    public function execute(PageVisit $visit): array
    {
        $latest = MaintenanceCheckState::query()->whereNotNull('checked_at')->latest('checked_at')->first();

        return [
            'attention' => $this->attention->items($visit),
            'checkedAt' => $latest?->checked_at?->toIso8601String(),
        ];
    }
}
