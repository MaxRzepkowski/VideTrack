<?php

namespace App\Library\Data;

use App\Library\HistoryFactsQuery;
use App\Support\QueryRow;
use stdClass;

/** What one position's plays add up to — within one viewing cycle, or merged across them. */
final readonly class PlayTally
{
    public function __construct(
        /** Every entry: completions, skips and drops alike. */
        public int $entries,
        public int $completions,
        public ?string $lastWatchedAt,
        public ?string $lastDroppedAt,
        /** Sortable (watched_at, id) of the newest completion, so equal timestamps tiebreak on creation order. */
        public ?string $lastWatchedKey,
        public ?string $lastDroppedKey,
        public ?int $droppedAmount,
        /** Every entry's recorded cost, drops included: those hours were really played. */
        public ?int $totalAmount,
    ) {}

    /** One row of the per-cycle aggregate {@see HistoryFactsQuery} selects. */
    public static function fromRow(stdClass $row): self
    {
        return new self(
            entries: QueryRow::int($row, 'entry_count'),
            completions: QueryRow::int($row, 'play_count'),
            lastWatchedAt: QueryRow::textOrNull($row, 'last_watched_at'),
            lastDroppedAt: QueryRow::textOrNull($row, 'last_dropped_at'),
            lastWatchedKey: QueryRow::textOrNull($row, 'last_watched_key'),
            lastDroppedKey: QueryRow::textOrNull($row, 'last_dropped_key'),
            droppedAmount: QueryRow::intOrNull($row, 'dropped_amount'),
            totalAmount: QueryRow::intOrNull($row, 'total_amount'),
        );
    }

    /**
     * @param  non-empty-list<self>  $tallies
     */
    public static function merge(array $tallies): self
    {
        $amounts = array_filter(array_column($tallies, 'totalAmount'), fn (?int $amount): bool => $amount !== null);

        return new self(
            entries: array_sum(array_column($tallies, 'entries')),
            completions: array_sum(array_column($tallies, 'completions')),
            lastWatchedAt: self::latest(array_column($tallies, 'lastWatchedAt')),
            lastDroppedAt: self::latest(array_column($tallies, 'lastDroppedAt')),
            lastWatchedKey: self::latest(array_column($tallies, 'lastWatchedKey')),
            lastDroppedKey: self::latest(array_column($tallies, 'lastDroppedKey')),
            droppedAmount: self::largest(array_column($tallies, 'droppedAmount')),
            totalAmount: $amounts === [] ? null : array_sum($amounts),
        );
    }

    /**
     * Whether the newest entry is a drop — the attempt closed as dropped and
     * nothing reopened it since (a completing play either replaces the drop
     * in place or comes after it).
     */
    public function latestIsDrop(): bool
    {
        return $this->lastDroppedKey !== null
            && ($this->lastWatchedKey === null || $this->lastDroppedKey > $this->lastWatchedKey);
    }

    /**
     * @param  list<string|null>  $values
     */
    private static function latest(array $values): ?string
    {
        $values = array_filter($values, fn (?string $value): bool => $value !== null && $value !== '');

        return $values === [] ? null : max($values);
    }

    /**
     * @param  list<int|null>  $values
     */
    private static function largest(array $values): ?int
    {
        $values = array_filter($values, fn (?int $value): bool => $value !== null);

        return $values === [] ? null : max($values);
    }
}
