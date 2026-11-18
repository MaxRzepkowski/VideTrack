<?php

namespace App\Actions\Users;

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorRef;
use App\Catalog\Data\CreatorLink;
use App\Catalog\ItemMetadata;
use App\Catalog\MediaItemSummaries;
use App\Catalog\MediaTypeRegistry;
use App\Http\Data\StatisticsOrigins;
use App\Models\MediaItem;
use App\Models\User;
use App\Users\Statistics\Data\MakerTally;
use App\Users\Statistics\Data\OriginTally;
use App\Users\Statistics\Data\RatedTitle;
use App\Users\Statistics\StatisticsScope;
use App\Users\Statistics\TitleLookups;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Where the period's titles come from: the countries and original languages
 * they name, and the networks they ran on, or, when none ran on any, the
 * studios that made them, each with its best rated titles. Each counts
 * titles, once per title.
 */
final readonly class BuildStatisticsOrigins
{
    /** A maker's best rated titles, shown beside the bubbles. */
    private const BEST = 5;

    public function __construct(
        private MediaTypeRegistry $types,
        private TitleLookups $lookups,
    ) {}

    public function execute(User $viewer, StatisticsScope $scope): StatisticsOrigins
    {
        $ids = TitleLookups::rootIds($scope->counted);
        $titles = $this->lookups->items($ids);
        $ratings = $this->lookups->ratings($viewer, $ids);
        $countries = [];
        $languages = [];
        $countryTypes = [];
        $languageTypes = [];
        $networks = [];
        $studios = [];

        foreach ($titles as $title) {
            $metadata = ItemMetadata::fromStored($title->metadata);

            foreach (array_unique($metadata->originCountries()) as $code) {
                $countries[$code] = ($countries[$code] ?? 0) + 1;
                $countryTypes[$title->type] = true;
            }

            if ($metadata->originalLanguage !== null) {
                $languages[$metadata->originalLanguage] = ($languages[$metadata->originalLanguage] ?? 0) + 1;
                $languageTypes[$title->type] = true;
            }

            $this->tally($networks, $metadata->networks(), $title->id);
            $this->tally($studios, $metadata->studios(), $title->id);
        }

        $shown = [];
        $positions = [];
        $makers = [];

        // Each best title travels once; a maker names its own by their place in that list.
        foreach ($this->ranked($networks !== [] ? $networks : $studios, $titles, $ratings) as $maker) {
            $best = [];

            foreach ($maker['best'] as $id) {
                if (! isset($positions[$id])) {
                    $positions[$id] = count($shown);
                    $title = $titles->get($id) ?? throw new LogicException("Title {$id} was counted but not read.");
                    $shown[] = new RatedTitle(MediaItemSummaries::summary($title), $ratings[$id] ?? null);
                }

                $best[] = $positions[$id];
            }

            $makers[] = new MakerTally($maker['creator'], count($maker['titles']), $best);
        }

        return new StatisticsOrigins(
            countries: $this->origins($countries),
            countryTypes: $this->inOrder($countryTypes),
            languages: $this->origins($languages),
            languageTypes: $this->inOrder($languageTypes),
            makers: $makers,
            makerKind: $makers === [] ? null : ($networks !== [] ? CreatorKind::Network : CreatorKind::Company),
            titles: $shown,
        );
    }

    /**
     * Files a title under each maker it names; the same maker listed twice on one title files it once.
     *
     * @param  array<string, array{creator: CreatorLink, titles: list<int>}>  $tally
     * @param  list<CreatorLink>  $links
     */
    private function tally(array &$tally, array $links, int $titleId): void
    {
        $seen = [];

        foreach ($links as $link) {
            // A maker the provider gave no page is told apart by its name alone.
            $key = $link->ref === null || $link->provider === null ? "name:{$link->name}" : (new CreatorRef($link->kind, $link->provider, $link->ref))->key();

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $tally[$key] ??= ['creator' => $link, 'titles' => []];
                $tally[$key]['titles'][] = $titleId;
            }
        }
    }

    /**
     * @param  array<string|int, int>  $counts  code => titles
     * @return list<OriginTally>
     */
    private function origins(array $counts): array
    {
        $tallies = [];

        foreach ($counts as $code => $titles) {
            $tallies[] = new OriginTally((string) $code, $titles);
        }

        usort($tallies, fn (OriginTally $a, OriginTally $b): int => [$b->titles, $a->code] <=> [$a->titles, $b->code]);

        return $tallies;
    }

    /**
     * The makers, most titles first, each with its best rated titles' ids.
     *
     * @param  array<string, array{creator: CreatorLink, titles: list<int>}>  $tally
     * @param  Collection<int, MediaItem>  $titles
     * @param  array<int, int>  $ratings
     * @return list<array{creator: CreatorLink, titles: list<int>, best: list<int>}>
     */
    private function ranked(array $tally, Collection $titles, array $ratings): array
    {
        $makers = array_map(fn (array $maker): array => [...$maker, 'best' => $this->best($maker['titles'], $titles, $ratings)], array_values($tally));

        usort($makers, fn (array $a, array $b): int => [count($b['titles']), $a['creator']->name] <=> [count($a['titles']), $b['creator']->name]);

        return $makers;
    }

    /**
     * The best rated of a maker's titles, each by the user's own rating, or
     * everyone else's where the user gave none; on a tie the user's goes first.
     *
     * @param  list<int>  $ids
     * @param  Collection<int, MediaItem>  $titles
     * @param  array<int, int>  $ratings
     * @return list<int>
     */
    private function best(array $ids, Collection $titles, array $ratings): array
    {
        $rank = function (int $id) use ($titles, $ratings): array {
            $title = $titles->get($id) ?? throw new LogicException("Title {$id} was counted but not read.");

            return [-($ratings[$id] ?? $title->community_rating ?? 0), isset($ratings[$id]) ? 0 : 1, $title->title];
        };

        usort($ids, fn (int $a, int $b): int => $rank($a) <=> $rank($b));

        return array_slice($ids, 0, self::BEST);
    }

    /**
     * @param  array<string, true>  $types
     * @return list<string>
     */
    private function inOrder(array $types): array
    {
        return array_values(array_filter($this->types->titleCodes(), fn (string $code): bool => isset($types[$code])));
    }
}
