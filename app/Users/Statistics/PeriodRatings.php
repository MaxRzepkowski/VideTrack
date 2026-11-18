<?php

namespace App\Users\Statistics;

/**
 * The user's ratings of a period's titles — the titles PeriodMarks names,
 * so the period card and the ratings section count one set — and, for those
 * everyone else rated too, how far the user's rating sits from theirs.
 */
final readonly class PeriodRatings
{
    /**
     * @param  array<int, int>  $ratings  title id => the user's rating
     * @param  array<int, float>  $community  title id => everyone else's average, for the rated titles that have one
     */
    public function __construct(
        public array $ratings,
        public array $community,
    ) {}

    /**
     * The user's rating less everyone else's: above zero the user rated the
     * title higher, below it lower.
     *
     * @return array<int, float> title id => gap, for the titles everyone else rated
     */
    public function gaps(): array
    {
        $gaps = [];

        foreach ($this->community as $id => $community) {
            $gaps[$id] = $this->ratings[$id] - $community;
        }

        return $gaps;
    }

    /**
     * The titles the user rated above everyone else, in no order.
     *
     * @return list<int>
     */
    public function higher(): array
    {
        return array_keys(array_filter($this->gaps(), fn (float $gap): bool => $gap > 0));
    }

    /**
     * The titles the user rated below everyone else, in no order.
     *
     * @return list<int>
     */
    public function lower(): array
    {
        return array_keys(array_filter($this->gaps(), fn (float $gap): bool => $gap < 0));
    }

    /** The titles the user rated exactly as everyone else did. */
    public function same(): int
    {
        return count($this->community) - count($this->higher()) - count($this->lower());
    }

    /** The gap on average, to two decimals; null when no rated title has everyone else's rating. */
    public function averageGap(): ?float
    {
        $gaps = $this->gaps();

        return $gaps === [] ? null : round(array_sum($gaps) / count($gaps), 2);
    }
}
