<?php

namespace App\Users\Statistics;

use App\Users\Statistics\Data\DayCount;
use App\Users\Statistics\Data\HistoryEntry;

/**
 * How many entries fell on each day of the user's clock, and which day had
 * the most: the earliest of the busiest, so a tie names the day it first
 * happened on.
 */
final readonly class DailyCounts
{
    /**
     * @param  array<string, int>  $counts  Y-m-d => entries, oldest day first
     */
    private function __construct(public array $counts) {}

    /**
     * @param  list<HistoryEntry>  $entries  oldest first
     */
    public static function of(array $entries): self
    {
        $counts = [];

        foreach ($entries as $entry) {
            $counts[$entry->day] = ($counts[$entry->day] ?? 0) + 1;
        }

        return new self($counts);
    }

    public function busiest(): ?DayCount
    {
        $best = null;

        foreach ($this->counts as $day => $count) {
            if ($best === null || $count > $best->count) {
                $best = new DayCount((string) $day, $count);
            }
        }

        return $best;
    }
}
