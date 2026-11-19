<?php

namespace App\Tracking\Surfaces;

use App\Catalog\Data\TypeCount;
use App\Catalog\MediaTypeRegistry;
use App\Enums\CountdownSort;
use App\Enums\SortDirection;
use App\Tracking\Data\CountdownEntry;
use App\Tracking\Data\CountdownFilters;
use App\Tracking\Data\EventCountdown;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The user's countdowns, soonest first, with what the filters read. The set
 * is small and already in memory, so search and sort run here rather than as
 * another query.
 */
final readonly class CountdownSet
{
    /** A search long enough for any title, short enough not to bloat the filtering. */
    public const MAX_QUERY = 100;

    /**
     * @param  list<array{type: string, title: string, names: list<string|null>, card: CountdownEntry|EventCountdown}>  $rows  soonest first
     */
    public function __construct(private array $rows) {}

    /**
     * How many count down per kind: a root type, or events.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach ($this->rows as $row) {
            $counts[$row['type']] = ($counts[$row['type']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Every kind a countdown is filed under, counting down or not: the root
     * types, then events.
     *
     * @return list<string>
     */
    public static function kindCodes(MediaTypeRegistry $types): array
    {
        return [...$types->rootCodes(), EventCountdown::FILTER_TYPE];
    }

    /**
     * The kinds counting down, with how many each: the root types in the
     * registry's order, then events, an option of their own.
     *
     * @return list<TypeCount>
     */
    public function kinds(MediaTypeRegistry $types): array
    {
        $counts = $this->counts();
        $kinds = TypeCount::inOrder($counts, $types);

        if (isset($counts[EventCountdown::FILTER_TYPE])) {
            $kinds[] = new TypeCount(EventCountdown::FILTER_TYPE, $counts[EventCountdown::FILTER_TYPE]);
        }

        return $kinds;
    }

    /**
     * The countdowns of the filters' kind (every kind without one) that the
     * search hits, in the order asked for.
     *
     * @return list<CountdownEntry|EventCountdown>
     */
    public function matching(CountdownFilters $filters): array
    {
        $rows = array_values(array_filter(
            $this->rows,
            fn (array $row) => ($filters->type === null || $row['type'] === $filters->type)
                && ($filters->q === null || $this->matches($row['names'], $filters->q)),
        ));

        // The set is soonest-first, so only the other axis re-sorts.
        if ($filters->sort === CountdownSort::Title) {
            usort($rows, fn (array $a, array $b) => strcasecmp($a['title'], $b['title']));
        }

        if ($filters->dir === SortDirection::Desc) {
            $rows = array_reverse($rows);
        }

        return array_map(fn (array $row): CountdownEntry|EventCountdown => $row['card'], $rows);
    }

    /**
     * One page of what the filters match. A page past the end is empty.
     *
     * @return LengthAwarePaginator<int, CountdownEntry|EventCountdown>
     */
    public function page(CountdownFilters $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $entries = $this->matching($filters);

        return new LengthAwarePaginator(
            array_slice($entries, ($page - 1) * $perPage, $perPage),
            count($entries),
            $perPage,
            $page,
        );
    }

    /** @param  list<string|null>  $names */
    private function matches(array $names, string $query): bool
    {
        foreach ($names as $name) {
            if ($name !== null && mb_stripos($name, $query) !== false) {
                return true;
            }
        }

        return false;
    }
}
