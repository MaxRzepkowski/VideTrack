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
 * The titles of a provider's all-time chart the user has not seen, from the
 * top down. The chart and how deep it is read are the subclass's.
 */
abstract readonly class ChartUnseen implements SuggestionSource
{
    public function __construct(
        private Charts $charts,
        private SuggestionFilter $filter,
    ) {}

    abstract protected function order(): ChartOrder;

    /** Titles read from the top of each type's chart. */
    abstract protected function depth(): int;

    public function origins(): array
    {
        return [];
    }

    public function wantedSets(User $user): array
    {
        return $this->charts->keys($this->order(), $this->depth());
    }

    public function groups(User $viewer, string $locale): array
    {
        $ranked = $this->charts->ranked($this->wantedSets($viewer), $locale);
        $standings = $this->filter->standings($viewer, array_column($ranked, 'title'));
        $suggestions = [];

        foreach ($ranked as $entry) {
            if ($standings->isOpen($entry['title'])) {
                $suggestions[] = new Suggestion($entry['title'], [__('suggestions.place_in_chart', ['rank' => $entry['rank']])]);
            }
        }

        return $suggestions === [] ? [] : [new SuggestionGroup($suggestions)];
    }
}
