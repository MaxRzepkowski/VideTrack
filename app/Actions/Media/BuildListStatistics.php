<?php

namespace App\Actions\Media;

use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\TypeCount;
use App\Catalog\MediaTypeDefinition;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProgressUnit;
use App\Enums\PositionStatus;
use App\Library\Data\ListStatisticsData;
use App\Library\Data\ListUnitTotal;
use App\Library\Data\UserMediaState;
use App\Models\MediaItem;

/**
 * The fixed overview of a list, independent of its current filters/page.
 * People and companies are list positions but live outside the media registry,
 * so they count as items without pretending they can be completed.
 *
 * How big a list is depends on what it holds: hours for what is watched, pages
 * for books, chapters for manga, issues for comics. Each unit totals in its own
 * bucket — summing them would invent a number nobody can read — and the buckets
 * come from the registry's `progressUnit`, so a new media type is a
 * registration, not an edit here.
 */
final readonly class BuildListStatistics
{
    public function __construct(private MediaTypeRegistry $types) {}

    /**
     * @param  iterable<MediaSummary>  $summaries
     * @param  array<string, UserMediaState>  $userStates
     */
    public function execute(iterable $summaries, array $userStates): ListStatisticsData
    {
        $itemCount = 0;
        $typeCounts = [];
        $trackableCount = 0;
        $completedCount = 0;
        $inProgressCount = 0;
        $droppedCount = 0;
        $upcomingCount = 0;

        /** @var array<string, array{unit: ProgressUnit, estimated: bool, amount: int, behindAmount: int, remainingAmount: int, itemsCounted: int, itemsTotal: int}> $buckets */
        $buckets = [];

        foreach ($summaries as $summary) {
            $itemCount++;
            $typeCounts[$summary->type] = ($typeCounts[$summary->type] ?? 0) + 1;

            if (! $this->types->has($summary->type)) {
                continue;
            }

            $trackableCount++;

            $definition = $this->types->get($summary->type);
            $state = $userStates[$summary->key()] ?? UserMediaState::untouched();
            $completed = $state->watched;

            // A dropped attempt is over, not open: it counts as neither
            // finished nor in progress, and stays in the denominator — the
            // list holds it, and giving up on it did not shorten the list.
            $dropped = ! $completed && $state->status === PositionStatus::Dropped;

            if ($completed) {
                $completedCount++;
            } elseif (! MediaItem::airedBy($summary->releaseDate, today())) {
                // Nobody can have finished what is not out: it stays off the
                // progress until it is, so a saga with a sequel announced is
                // not "unfinished" for it.
                $upcomingCount++;
            } elseif ($dropped) {
                $droppedCount++;
            } elseif ($this->inProgress($definition, $state)) {
                $inProgressCount++;
            }

            // Types whose own progress is counted in minutes (games) are sized
            // by the provider's average completion time — an estimate, which
            // is why it never shares a bucket with the runtime of a film.
            $unit = $definition->progressUnit ?? ProgressUnit::Minutes;
            $estimated = $definition->progressUnit === ProgressUnit::Minutes;
            $key = $unit->value.($estimated ? ':estimated' : '');

            $buckets[$key] ??= [
                'unit' => $unit,
                'estimated' => $estimated,
                'amount' => 0,
                'behindAmount' => 0,
                'remainingAmount' => 0,
                'itemsCounted' => 0,
                'itemsTotal' => 0,
            ];
            $buckets[$key]['itemsTotal']++;

            $size = $this->size($unit, $summary);

            if ($size === null) {
                continue;
            }

            // What is behind: the whole thing once completed, otherwise as far
            // as the user really got — a book left on page 300 has 300 pages
            // behind it whether the read is open or given up on. Capped at the
            // known total so the two halves add back up to the bucket's amount.
            $behind = $completed ? $size : min($size, max(0, $this->recorded($definition, $state)));

            $buckets[$key]['itemsCounted']++;
            $buckets[$key]['amount'] += $size;
            $buckets[$key]['behindAmount'] += $behind;
            $buckets[$key]['remainingAmount'] += $size - $behind;
        }

        return new ListStatisticsData(
            itemCount: $itemCount,
            types: $this->rankTypes($typeCounts),
            trackableCount: $trackableCount,
            completedCount: $completedCount,
            inProgressCount: $inProgressCount,
            droppedCount: $droppedCount,
            upcomingCount: $upcomingCount,
            totals: $this->rankTotals($buckets),
        );
    }

    /**
     * How much of the unit this position holds: minutes from the rolled-up
     * runtime, positions from the total the counter runs to (a book's pages,
     * a manga's chapters, a comic volume's issues) — the total the progress
     * inputs cap themselves at. NULL = the provider never said.
     */
    private function size(ProgressUnit $unit, MediaSummary $summary): ?int
    {
        $value = $unit === ProgressUnit::Minutes
            ? $summary->totalRuntime
            : $summary->progressTotal;

        return $value !== null && $value > 0 ? $value : null;
    }

    /**
     * How far into this position the user really got, in the position's own
     * unit: the counter of an open read, the page a given-up one stopped at,
     * or — for a container — the runtime of the episodes actually watched.
     * A show abandoned at season two keeps those hours behind it, the same
     * way an abandoned book keeps its pages.
     */
    private function recorded(MediaTypeDefinition $definition, UserMediaState $state): int
    {
        if ($definition->progressUnit === null) {
            return $state->watchedMinutes ?? 0;
        }

        // A drop converts the open state into a play, so the counter it froze
        // at lives on the play row rather than in `progress`.
        return $state->progress ?? $state->droppedAmount ?? 0;
    }

    /**
     * Started but not finished. Flat progress types say so themselves (an open
     * attempt, or a counter off zero); containers have it derived for them.
     * Either way a dropped attempt is out: it is over, not open.
     */
    private function inProgress(MediaTypeDefinition $definition, UserMediaState $state): bool
    {
        if ($state->status === PositionStatus::Dropped) {
            return false;
        }

        if ($definition->progressUnit === null) {
            return $state->inProgress;
        }

        return in_array($state->status, [PositionStatus::InProgress, PositionStatus::OnHold], true)
            || ($state->progress ?? 0) > 0;
    }

    /**
     * The type breakdown, biggest group first; ties keep a stable alphabetical
     * order so the tooltip does not reshuffle between two equal groups.
     *
     * @param  array<string, int>  $counts
     * @return list<TypeCount>
     */
    private function rankTypes(array $counts): array
    {
        ksort($counts);
        arsort($counts, SORT_NUMERIC);

        return array_map(
            fn (string $type, int $count): TypeCount => new TypeCount($type, $count),
            array_keys($counts),
            array_values($counts),
        );
    }

    /**
     * Buckets in a fixed reading order (time first, then the reading counters),
     * dropping the ones no position could put a number into — a chip reading
     * "—" is noise the item breakdown already explains.
     *
     * @param  array<string, array{unit: ProgressUnit, estimated: bool, amount: int, behindAmount: int, remainingAmount: int, itemsCounted: int, itemsTotal: int}>  $buckets
     * @return list<ListUnitTotal>
     */
    private function rankTotals(array $buckets): array
    {
        $order = array_flip(array_map(fn (ProgressUnit $unit): string => $unit->value, ProgressUnit::cases()));

        $totals = array_values(array_filter($buckets, fn (array $bucket): bool => $bucket['amount'] > 0));

        usort($totals, fn (array $a, array $b): int => [$order[$a['unit']->value], $a['estimated']] <=> [$order[$b['unit']->value], $b['estimated']]);

        return array_map(fn (array $total): ListUnitTotal => new ListUnitTotal(...$total), $totals);
    }
}
