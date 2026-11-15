<?php

namespace App\Catalog;

use App\Models\MediaItem;

/**
 * How far an item's progress counter runs: a book's pages, a manga's chapters,
 * a comic volume's issues. It caps what a session can log and turns a
 * percentage into a position. Types that count time, or nothing, have no total.
 */
final readonly class ProgressTotals
{
    public function __construct(private MediaTypeRegistry $types) {}

    /** Null also when the provider never said. */
    public function of(MediaItem $item): ?int
    {
        $unit = $this->unit($item);

        return $unit === null ? null : ItemMetadata::fromStored($item->metadata)->progressTotal($unit);
    }

    /**
     * How much of the title is left, 1.0 (untouched) down to just above 0.0:
     * reading types measure against their page / chapter / issue count, games
     * against the provider's time-to-beat estimate in `runtime`.
     *
     * Null when the total says nothing about what is left, and those titles
     * sort last. That covers a missing total — but also a progress that has
     * passed it, which is the normal state of a game played for its own sake
     * rather than for an ending: an estimate 400 hours behind the player does
     * not mean the game is nearly over, so it must not lead a list.
     */
    public function remainingShare(MediaItem $item, ?int $progress): ?float
    {
        $total = $this->of($item) ?? $item->runtime;
        $progress = max(0, $progress ?? 0);

        if ($total === null || $total <= 0 || $progress >= $total) {
            return null;
        }

        return 1 - $progress / $total;
    }

    /** A row of a type this instance no longer registers (its module switched off) counts nothing. */
    public function unit(MediaItem $item): ?ProgressUnit
    {
        return $this->types->has($item->type) ? $this->types->get($item->type)->progressUnit : null;
    }
}
