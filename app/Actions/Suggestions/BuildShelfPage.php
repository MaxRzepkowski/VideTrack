<?php

namespace App\Actions\Suggestions;

use App\Catalog\Data\TypeCount;
use App\Catalog\MediaTypeRegistry;
use App\Library\LibraryStates;
use App\Models\User;
use App\Suggestions\Contracts\SuggestionSource;
use App\Suggestions\Data\OpenedSubject;
use App\Suggestions\Data\ShelfQuery;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Data\SuggestionGroup;
use App\Suggestions\Data\SuggestionOrigin;
use App\Suggestions\Data\SuggestionSubject;
use App\Suggestions\ShelfArranger;
use App\Suggestions\ShelfSort;
use App\Support\PageInfo;

/**
 * One kind of suggestion in full. A source without subjects is a paged grid
 * of its titles. A source with subjects (collections) lists the subjects
 * first; a subject's own page then shows its titles. Either is searched,
 * filtered and sorted by the page's controls.
 */
final readonly class BuildShelfPage
{
    public const TITLES_PER_PAGE = 40;

    public const SUBJECTS_PER_PAGE = 40;

    public function __construct(
        private LibraryStates $states,
        private MediaTypeRegistry $types,
        private ShelfArranger $arranger,
    ) {}

    /**
     * @return array<string, mixed>|null null when the address names a subject the source does not have for the user
     */
    public function execute(User $viewer, string $key, SuggestionSource $source, ?string $subject, ShelfQuery $query, string $locale): ?array
    {
        $all = $source->groups($viewer, $locale);
        $opened = null;

        if ($subject !== null) {
            $opened = array_values(array_filter($all, fn (SuggestionGroup $group): bool => $group->key === $subject))[0] ?? null;

            if ($opened === null) {
                return null;
            }

            $all = [$opened];
        }

        $everything = $this->titles($all);
        $listsSubjects = $opened === null && $all !== [] && $all[0]->key !== null;
        $ways = $source->origins();
        $sorts = $listsSubjects
            ? ShelfSort::forSubjects()
            : ShelfSort::forTitles(array_filter($everything, fn (Suggestion $suggestion): bool => $suggestion->addedOn !== null) !== []);

        // A wish the shelf cannot answer falls back instead of emptying the page.
        $query = $query->settled(
            isset($ways[(string) $query->origin]) ? $query->origin : null,
            in_array($query->sort, $sorts, true) ? $query->sort : ShelfSort::Suggested,
        );

        $groups = $this->arranger->narrow($all, $query);
        $total = count($this->titles($groups));

        $props = [
            'shelf' => ['key' => $key, 'title' => $source->title(), 'description' => $source->description()],
            'subject' => $opened === null ? null : OpenedSubject::of($opened),
            'types' => TypeCount::inOrder($this->tally($everything, fn (Suggestion $suggestion): string => $suggestion->media->type), $this->types),
            'origins' => array_map(
                fn (string $way, string $label): SuggestionOrigin => new SuggestionOrigin($way, $label, count(array_filter($everything, fn (Suggestion $suggestion): bool => in_array($way, $suggestion->origins, true)))),
                array_keys($ways),
                array_values($ways),
            ),
            'decades' => $this->decades($everything),
            'sorts' => $sorts,
            'filters' => [
                'type' => $query->type,
                'origin' => $query->origin,
                'decade' => $query->decade,
                'q' => $query->q,
                'sort' => $query->sort,
                'dir' => $query->dir,
            ],
        ];

        if ($listsSubjects) {
            [$shown, $pages, $page] = $this->pageOf($this->arranger->sortSubjects($groups, $query->sort, $query->dir), self::SUBJECTS_PER_PAGE, $query->page);

            return [...$props,
                'subjects' => array_map(SuggestionSubject::of(...), $shown),
                'suggestions' => [],
                'pagination' => new PageInfo($page, $pages, $total),
                'userStates' => [],
            ];
        }

        [$shown, $pages, $page] = $this->pageOf($this->arranger->sortTitles($this->titles($groups), $query->sort, $query->dir), self::TITLES_PER_PAGE, $query->page);
        $cards = array_map(fn (Suggestion $suggestion) => $suggestion->media, $shown);

        return [...$props,
            'subjects' => null,
            'suggestions' => $shown,
            'pagination' => new PageInfo($page, $pages, $total),
            'userStates' => fn (): array => $this->states->resolve($viewer, $cards),
        ];
    }

    /**
     * @param  list<SuggestionGroup>  $groups
     * @return list<Suggestion>
     */
    private function titles(array $groups): array
    {
        return array_merge([], ...array_map(fn (SuggestionGroup $group): array => $group->suggestions, $groups));
    }

    /**
     * The decades the shelf's titles came out in, the latest first, each with
     * how many titles — counted before any filter, like the type options.
     *
     * @param  list<Suggestion>  $titles
     * @return list<array{decade: int, count: int}>
     */
    private function decades(array $titles): array
    {
        $counts = $this->tally(
            array_values(array_filter($titles, fn (Suggestion $suggestion): bool => $suggestion->media->year !== null)),
            fn (Suggestion $suggestion): int => intdiv((int) $suggestion->media->year, 10) * 10,
        );
        krsort($counts);

        return array_map(fn (int $decade, int $count): array => ['decade' => $decade, 'count' => $count], array_keys($counts), array_values($counts));
    }

    /**
     * @template K of array-key
     *
     * @param  list<Suggestion>  $titles
     * @param  callable(Suggestion): K  $by
     * @return array<K, int>
     */
    private function tally(array $titles, callable $by): array
    {
        $counts = [];

        foreach ($titles as $title) {
            $counts[$by($title)] = ($counts[$by($title)] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The page asked for, or the last one when the address names a page past it.
     *
     * @template T
     *
     * @param  list<T>  $entries
     * @return array{list<T>, int, int}
     */
    private function pageOf(array $entries, int $perPage, int $page): array
    {
        $pages = max(1, (int) ceil(count($entries) / $perPage));
        $page = min($page, $pages);

        return [array_slice($entries, ($page - 1) * $perPage, $perPage), $pages, $page];
    }
}
