<?php

namespace App\Tasks\CatalogRefresh;

/**
 * Why a root waits to be refreshed, in the order the queue empties: what
 * someone would be told about first, then what a provider says changed,
 * then the day's turn of the cycle through everything else.
 */
enum RefreshReason: string
{
    case Active = 'active';
    case Changed = 'changed';
    case Cycle = 'cycle';

    /** Lower goes first. */
    public function rank(): int
    {
        return match ($this) {
            self::Active => 0,
            self::Changed => 1,
            self::Cycle => 2,
        };
    }

    /** The more urgent of two reasons: a root never waits for a lesser one. */
    public function strongerOf(?self $other): self
    {
        return $other !== null && $other->rank() < $this->rank() ? $other : $this;
    }
}
