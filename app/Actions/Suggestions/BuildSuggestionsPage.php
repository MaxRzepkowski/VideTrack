<?php

namespace App\Actions\Suggestions;

use App\Models\User;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Data\SuggestionGroup;
use App\Suggestions\Data\SuggestionShelf;
use App\Suggestions\SuggestionSources;
use Illuminate\Support\Facades\Cache;

/**
 * The suggestions page: the kinds of suggestion that have something for the
 * user, each with how many titles wait behind it. The titles themselves are
 * on each kind's own page.
 */
final readonly class BuildSuggestionsPage
{
    /** Posters on a kind's tile. */
    public const POSTERS = 6;

    /** Seconds the tiles of an account stand before they are counted again. */
    public const KINDS_REMEMBERED_SECONDS = 600;

    public function __construct(private SuggestionSources $sources) {}

    /**
     * @return array{shelves: list<SuggestionShelf>}
     */
    public function execute(User $viewer, string $locale): array
    {
        // Every kind is worked out in full just to count it, which adds up
        // to most of a second on a large library. The tiles only say roughly
        // how much waits behind each, so they may lag a few minutes; a
        // kind's own page is always worked out afresh.
        return ['shelves' => Cache::remember(
            "suggestions:kinds:{$viewer->id}:{$locale}",
            self::KINDS_REMEMBERED_SECONDS,
            fn (): array => $this->shelves($viewer, $locale),
        )];
    }

    /**
     * @return list<SuggestionShelf>
     */
    private function shelves(User $viewer, string $locale): array
    {
        $shelves = [];

        foreach ($this->sources->codes() as $code) {
            $source = $this->sources->get($code);
            $groups = $source->groups($viewer, $locale);
            $total = array_sum(array_map(fn (SuggestionGroup $group): int => count($group->suggestions), $groups));

            if ($total === 0) {
                continue;
            }

            $shelves[] = new SuggestionShelf(
                key: $code,
                title: $source->title(),
                description: $source->description(),
                total: $total,
                // A few posters to tell the kinds apart at a glance.
                posters: $this->posters($groups),
            );
        }

        return $shelves;
    }

    /**
     * The first few pictures behind a kind: a subject's own where the source
     * has subjects (failing that, its first title's), else the titles'.
     *
     * @param  list<SuggestionGroup>  $groups
     * @return list<string>
     */
    private function posters(array $groups): array
    {
        $posters = [];

        foreach ($groups as $group) {
            $titles = array_map(fn (Suggestion $suggestion): ?string => $suggestion->media->posterPath, $group->suggestions);
            $candidates = $group->key === null ? $titles : [$group->posterPath ?? ($titles[0] ?? null)];

            foreach ($candidates as $poster) {
                if ($poster !== null && ! in_array($poster, $posters, true)) {
                    $posters[] = $poster;
                }

                if (count($posters) === self::POSTERS) {
                    return $posters;
                }
            }
        }

        return $posters;
    }
}
