<?php

namespace App\Integrations\TvTime;

/** The two generations of TV Time's tracking table, by the column each names its rows with. */
enum TvTimeTracking
{
    /** v2: every row has a `key` saying what it is. */
    case Keyed;

    /** The older table: rows named by `type-uuid-n`. */
    case Named;

    public function marker(): string
    {
        return match ($this) {
            self::Keyed => 'key',
            self::Named => 'type-uuid-n',
        };
    }
}
