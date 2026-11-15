<?php

namespace App\Suggestions;

use App\Enums\SortDirection;
use App\Suggestions\Data\ShelfQuery;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Data\SuggestionGroup;

/**
 * Narrows a shelf to what its controls ask for and puts it in the order
 * asked. A source hands its suggestions over in its own order; this never
 * changes what is suggested, only what of it is shown and how it is read.
 */
final readonly class ShelfArranger
{
    /**
     * The groups with only the titles that pass; a group left empty is
     * dropped. A search that names a subject keeps the whole of it.
     *
     * @param  list<SuggestionGroup>  $groups
     * @return list<SuggestionGroup>
     */
    public function narrow(array $groups, ShelfQuery $query): array
    {
        $narrowed = [];

        foreach ($groups as $group) {
            $named = $query->q !== null && $group->title !== null && mb_stripos($group->title, $query->q) !== false;
            $admits = $named ? $query->withoutSearch()->admits(...) : $query->admits(...);
            $suggestions = array_values(array_filter($group->suggestions, $admits));

            if ($suggestions !== []) {
                $narrowed[] = new SuggestionGroup($suggestions, $group->key, $group->title, $group->href, $group->caption, $group->posterPath, $group->finished, $group->total);
            }
        }

        return $narrowed;
    }

    /**
     * @param  list<SuggestionGroup>  $subjects
     * @return list<SuggestionGroup>
     */
    public function sortSubjects(array $subjects, ShelfSort $sort, SortDirection $dir): array
    {
        return $this->sorted($subjects, $dir, match ($sort) {
            ShelfSort::Remaining => fn (SuggestionGroup $group): int => count($group->suggestions),
            ShelfSort::Progress => fn (SuggestionGroup $group): float => $group->total > 0 ? ($group->finished ?? 0) / $group->total : 0.0,
            ShelfSort::Release => fn (SuggestionGroup $group): string => array_reduce(
                $group->suggestions,
                fn (string $latest, Suggestion $suggestion): string => max($latest, (string) $suggestion->media->releaseDate),
                '',
            ),
            ShelfSort::Title => fn (SuggestionGroup $group): string => mb_strtolower((string) $group->title),
            default => null,
        });
    }

    /**
     * @param  list<Suggestion>  $titles
     * @return list<Suggestion>
     */
    public function sortTitles(array $titles, ShelfSort $sort, SortDirection $dir): array
    {
        return $this->sorted($titles, $dir, match ($sort) {
            ShelfSort::Release => fn (Suggestion $suggestion): string => (string) $suggestion->media->releaseDate,
            ShelfSort::Added => fn (Suggestion $suggestion): string => (string) $suggestion->addedOn,
            ShelfSort::Rating => fn (Suggestion $suggestion): float => $suggestion->media->communityRating ?? 0.0,
            ShelfSort::Title => fn (Suggestion $suggestion): string => mb_strtolower($suggestion->media->title),
            default => null,
        });
    }

    /**
     * Ascending by the value; without one, the order given (the source's
     * own). Descending is the same read backwards. Ties keep their order.
     *
     * @template T
     *
     * @param  list<T>  $entries
     * @param  (callable(T): (int|float|string))|null  $value
     * @return list<T>
     */
    private function sorted(array $entries, SortDirection $dir, ?callable $value): array
    {
        if ($value !== null) {
            $keyed = array_map(fn ($entry, int $index): array => [$value($entry), $index, $entry], $entries, array_keys($entries));
            usort($keyed, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $entries = array_column($keyed, 2);
        }

        return $dir === SortDirection::Desc ? array_reverse($entries) : $entries;
    }
}
