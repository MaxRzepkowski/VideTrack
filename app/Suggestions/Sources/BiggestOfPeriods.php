<?php

namespace App\Suggestions\Sources;

use App\Catalog\ChartOrder;
use App\Models\User;
use App\Suggestions\Charts;
use App\Suggestions\Contracts\SuggestionSource;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Data\SuggestionGroup;
use App\Suggestions\SuggestionFilter;

/**
 * The best known titles of each span of years (a year, a decade) that the
 * user has not seen, a subject per span, the latest first. Which spans and
 * how deep each is read are the subclass's.
 */
abstract readonly class BiggestOfPeriods implements SuggestionSource
{
    public function __construct(
        private Charts $charts,
        private SuggestionFilter $filter,
    ) {}

    /**
     * The spans, latest first.
     *
     * @return list<array{from: int, to: int}>
     */
    abstract protected function periods(): array;

    /** Titles read from the top of each type's chart of a span. */
    abstract protected function depth(): int;

    public function origins(): array
    {
        return [];
    }

    public function wantedSets(User $user): array
    {
        return array_merge([], ...array_map(
            fn (array $period): array => $this->charts->keys(ChartOrder::MostVoted, $this->depth(), $period['from'], $period['to']),
            $this->periods(),
        ));
    }

    public function groups(User $viewer, string $locale): array
    {
        // Every period's charts in one read, not a read per year.
        $ranked = $this->charts->rankedPerGroup(array_map(
            fn (array $period): array => $this->charts->keys(ChartOrder::MostVoted, $this->depth(), $period['from'], $period['to']),
            $this->periods(),
        ), $locale);

        $standings = $this->filter->standings($viewer, array_column(array_merge([], ...$ranked), 'title'));
        $groups = [];

        foreach ($this->periods() as $index => $period) {
            $titles = array_column($ranked[$index], 'title');
            $missing = array_values(array_filter($titles, $standings->isOpen(...)));

            if ($missing === []) {
                continue;
            }

            $name = $period['from'] === $period['to'] ? (string) $period['from'] : "{$period['from']}–{$period['to']}";
            $finished = count(array_filter($titles, $standings->hasFinished(...)));
            $caption = __('suggestions.watched_of_parts', ['watched' => $finished, 'total' => count($titles)]);

            $groups[] = new SuggestionGroup(
                suggestions: array_map(fn ($title) => new Suggestion($title, [$name]), $missing),
                key: "{$period['from']}-{$period['to']}",
                title: $name,
                caption: $caption,
                finished: $finished,
                total: count($titles),
            );
        }

        return $groups;
    }
}
