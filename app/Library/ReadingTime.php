<?php

namespace App\Library;

use App\Accounts\Preferences\UserPreferences;
use App\Catalog\ProgressUnit;

/**
 * What reading took, estimated: an amount in pages, chapters or issues
 * turned into minutes at the user's own reading pace. Only an estimate, so
 * every time it goes into says so; time watched and played is what was
 * logged and never passes through here.
 */
final readonly class ReadingTime
{
    private const MINUTES_PER_HOUR = 60;

    public function __construct(private UserPreferences $preferences) {}

    /** The minutes reading an amount in a unit took, at the user's pace; null for no unit or one that is time already. */
    public function of(?ProgressUnit $unit, int $amount): ?int
    {
        $pace = $unit === null ? null : $this->preferences->readingPace($unit);

        return $pace === null ? null : (int) round($amount * self::MINUTES_PER_HOUR / $pace);
    }
}
