<?php

namespace App\Users;

use App\Enums\PlayOutcome;
use App\Library\PlayCost;
use App\Support\QueryRow;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Collection;
use stdClass;

/**
 * What every day on a page of the history cost, bucketed by unit — minutes
 * never add up with pages, chapters or issues. Priced by the same rule as the
 * dashboard tiles.
 */
final readonly class HistoryDayTotals
{
    public function __construct(private PlayCost $cost) {}

    /**
     * The columns the history's day scan has to bring for this.
     *
     * @return list<string|Expression>
     */
    public function columns(): array
    {
        return [
            'plays.amount as amount',
            'plays.outcome as outcome',
            'media_items.type as item_type',
            'media_items.runtime as runtime',
            $this->cost->titleRuntimeColumn(),
        ];
    }

    /**
     * @param  Collection<int, stdClass>  $rows  the day scan, with columns()
     * @param  array<string, list<int>>  $pageDays
     * @return array<string, array<string, int>>
     */
    public function of(Collection $rows, array $pageDays): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $day = QueryRow::text($row, 'day');

            if (! isset($pageDays[$day])) {
                continue;
            }

            $cost = $this->cost->of(
                QueryRow::text($row, 'item_type'),
                PlayOutcome::from(QueryRow::text($row, 'outcome')),
                QueryRow::intOrNull($row, 'amount'),
                QueryRow::intOrNull($row, 'runtime'),
                QueryRow::intOrNull($row, 'title_runtime'),
            );

            if ($cost !== null) {
                [$unit, $amount] = $cost;
                $totals[$day][$unit] = ($totals[$day][$unit] ?? 0) + $amount;
            }
        }

        return $totals;
    }
}
