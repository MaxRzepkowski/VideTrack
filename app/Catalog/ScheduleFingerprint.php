<?php

namespace App\Catalog;

use App\Models\MediaItem;

/**
 * What a stored title tells its users' calendars, notifications and progress,
 * as one value: every part with its date and status, and how far the title's
 * counter runs. The count is how a flat title grows — a comic's new issue or a
 * manga's new chapter moves no date. Two refreshes that leave the same value
 * changed nothing a user would see, however much the ratings or the
 * overviews moved.
 */
final readonly class ScheduleFingerprint
{
    public function __construct(
        private Hierarchy $hierarchy,
        private ProgressTotals $totals,
    ) {}

    public function of(MediaItem $root): string
    {
        $parts = MediaItem::query()
            ->whereIn('id', $this->hierarchy->treeIdsQuery([$root->id]))
            ->orderBy('type')
            ->orderBy('provider_ref')
            ->get(['type', 'provider_ref', 'release_date', 'release_date_precision', 'status'])
            // A date short of a day ("2028") is another date than that day; an
            // exact one keeps the plain value.
            ->map(fn (MediaItem $part): string => "{$part->type}:{$part->provider_ref}={$part->release_date?->toDateString()}"
                .($part->release_date_precision === DatePrecision::Day ? '' : "~{$part->release_date_precision->value}")
                .":{$part->status?->value}")
            ->implode('|');

        // Read again rather than from the model passed in, which a refresh may leave as it was.
        $stored = MediaItem::query()->whereKey($root->id)->first(['id', 'type', 'metadata']);
        $total = $stored === null ? null : $this->totals->of($stored);

        return md5("{$parts}#{$total}");
    }
}
