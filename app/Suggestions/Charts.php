<?php

namespace App\Suggestions;

use App\Catalog\CardSnapshot;
use App\Catalog\ChartOrder;
use App\Catalog\Contracts\SupportsCharts;
use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use App\Catalog\Sets\ChartRef;
use App\Catalog\Sets\SetKey;
use App\Catalog\Sets\SetStore;

/**
 * What the shelves built on charts share: which chart sets this instance
 * can have (one per charted type that is switched on), and their fetched
 * titles in rank order.
 */
final readonly class Charts
{
    public function __construct(
        private ProviderRegistry $providers,
        private MediaTypeRegistry $types,
        private SetStore $sets,
    ) {}

    /**
     * The sets of one chart, a set per type some provider charts.
     *
     * @return list<SetKey>
     */
    public function keys(ChartOrder $order, int $size, ?int $fromYear = null, ?int $toYear = null): array
    {
        $keys = [];

        foreach ($this->providers->allSupporting(SupportsCharts::class) as $code => $provider) {
            if (! $provider instanceof SupportsCharts) {
                continue;
            }

            foreach ($provider->chartedTypes() as $type) {
                if ($this->types->has($type)) {
                    $keys[] = (new ChartRef($type, $order, $size, $fromYear, $toYear))->key($code);
                }
            }
        }

        return $keys;
    }

    /**
     * The fetched titles of the sets, each with its place in its own chart,
     * the higher places first across the sets (films and shows interleave).
     *
     * @param  list<SetKey>  $keys
     * @return list<array{rank: int, title: MediaSummary}>
     */
    public function ranked(array $keys, string $locale): array
    {
        return $this->rankedPerGroup(['all' => $keys], $locale)['all'];
    }

    /**
     * The same for several charts at once (a chart per year), read together.
     *
     * @template TGroup of array-key
     *
     * @param  array<TGroup, list<SetKey>>  $groups
     * @return array<TGroup, list<array{rank: int, title: MediaSummary}>>
     */
    public function rankedPerGroup(array $groups, string $locale): array
    {
        $sets = $this->sets->read(array_merge([], ...array_values($groups)), $locale);
        $ranked = [];

        foreach ($groups as $group => $keys) {
            $ofGroup = [];

            foreach ($keys as $key) {
                $set = $sets[$key->id()] ?? null;

                // A chart not fetched yet has nothing to rank.
                foreach ($set === null ? [] : $set->titles as $title) {
                    $ofGroup[] = ['rank' => $title->position, 'title' => CardSnapshot::of($title)];
                }
            }

            usort($ofGroup, fn (array $a, array $b): int => [$a['rank'], $a['title']->type] <=> [$b['rank'], $b['title']->type]);
            $ranked[$group] = $ofGroup;
        }

        return $ranked;
    }
}
