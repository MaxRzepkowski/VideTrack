<?php

namespace App\Users\Statistics;

use App\Users\Statistics\Data\HistoryEntry;
use App\Users\Statistics\Data\PeriodSpan;
use Carbon\CarbonImmutable;

/**
 * What one view of the statistics page reads: the user's whole history, the
 * period and the type, and the slices every section needs, cut once.
 */
final readonly class StatisticsScope
{
    /** @var list<HistoryEntry> the chosen type's whole history */
    public array $entries;

    /** @var list<HistoryEntry> every type's entries in the period */
    public array $inPeriod;

    /** @var list<HistoryEntry> the chosen type's entries in the period */
    public array $shown;

    /** @var list<HistoryEntry> of those, the finished ones */
    public array $completions;

    /** @var list<HistoryEntry> of those, the entries that make their titles the period's */
    public array $counted;

    /**
     * @param  list<HistoryEntry>  $everything  every type's whole history, oldest first
     * @param  string  $today  Y-m-d on the user's clock
     */
    public function __construct(
        public StatisticsPeriod $period,
        public ?string $type,
        public array $everything,
        public string $today,
    ) {
        $this->entries = $type === null ? $everything : array_values(array_filter($everything, fn (HistoryEntry $entry): bool => $entry->rootType === $type));
        $this->inPeriod = array_values(array_filter($everything, fn (HistoryEntry $entry): bool => $period->contains($entry->day)));
        $this->shown = $type === null ? $this->inPeriod : array_values(array_filter($this->inPeriod, fn (HistoryEntry $entry): bool => $entry->rootType === $type));
        $this->completions = array_values(array_filter($this->shown, fn (HistoryEntry $entry): bool => $entry->completed));
        $this->counted = array_values(array_filter($this->shown, fn (HistoryEntry $entry): bool => $entry->countsTitle));
    }

    /** The chosen type's first finished entry ever. */
    public function firstCompletion(): ?HistoryEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->completed) {
                return $entry;
            }
        }

        return null;
    }

    /** The period with its days: the whole history starts at its first finished entry and runs to today. */
    public function span(): PeriodSpan
    {
        $from = $this->period->from ?? $this->firstCompletion()?->day;
        $to = $this->period->to ?? $this->today;
        $until = min($to, $this->today);

        return new PeriodSpan(
            kind: $this->period->kind,
            year: $this->period->year(),
            from: $from,
            to: $to,
            days: $from === null || $until < $from ? 0 : (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($until)) + 1,
        );
    }
}
