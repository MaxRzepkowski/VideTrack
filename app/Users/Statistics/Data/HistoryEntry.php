<?php

namespace App\Users\Statistics\Data;

use App\Catalog\ProgressUnit;
use App\Enums\PlayOutcome;
use App\Library\PlayCost;
use App\Library\ReadingTime;
use Carbon\CarbonImmutable;

/** One history entry as the statistics read it: what, under which title, when (the user's clock) and at what cost. */
final readonly class HistoryEntry
{
    public function __construct(
        public int $itemId,
        public string $itemType,
        /** The title the library files the entry under (a show for an episode). */
        public int $rootId,
        public string $rootType,
        public PlayOutcome $outcome,
        /** Something finished: a completed entry of a type that is played (see UserHistory). */
        public bool $completed,
        /** Whether it makes its title one of the period's (see UserHistory). */
        public bool $countsTitle,
        /** The cost's unit (PlayCost::WATCH or a progress unit); null when it cost nothing countable. */
        public ?string $unit,
        public int $amount,
        /** On the user's clock. */
        public CarbonImmutable $at,
        /** Y-m-d of `$at`. */
        public string $day,
    ) {}

    /** The minutes it took as logged, watched or played; nothing for an entry counted in a unit that is read. */
    public function loggedMinutes(): int
    {
        return $this->unit === PlayCost::WATCH || $this->unit === ProgressUnit::Minutes->value ? $this->amount : 0;
    }

    /** The minutes its reading took, estimated; null for an entry that was not read. */
    public function readMinutes(ReadingTime $reading): ?int
    {
        return $reading->of($this->unit === null ? null : ProgressUnit::tryFrom($this->unit), $this->amount);
    }

    /** The time it took: as logged for what was watched or played, estimated for what was read. */
    public function minutes(ReadingTime $reading): int
    {
        return $this->loggedMinutes() + ($this->readMinutes($reading) ?? 0);
    }
}
