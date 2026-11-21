<?php

namespace App\Catalog\Sets;

use App\Catalog\Contracts\SupportsCharts;
use App\Catalog\Contracts\SupportsCollections;
use App\Catalog\Contracts\SupportsPersonPage;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\PersonCredit;
use App\Catalog\ItemNotFoundException;
use App\Catalog\MatureChoices;
use App\Catalog\ProviderRegistry;
use App\Support\ExternalServiceException;

/**
 * Asks a provider for a set, by the capability its kind needs. A provider
 * without the capability has no sets of that kind.
 */
final readonly class SetFetcher
{
    public function __construct(private ProviderRegistry $providers) {}

    /**
     * @throws ItemNotFoundException the provider no longer has the set
     * @throws ExternalServiceException
     */
    public function fetch(SetKey $key, string $locale): ?FetchedSet
    {
        $provider = $this->providers->find($key->provider);

        return match ($key->kind) {
            SetKind::Collection => $provider instanceof SupportsCollections ? $this->collection($provider, $key->ref, $locale) : null,
            SetKind::Filmography => $provider instanceof SupportsPersonPage ? $this->filmography($provider, $key->provider, $key->ref, $locale) : null,
            SetKind::Chart => $provider instanceof SupportsCharts ? $this->chart($provider, $key->ref, $locale) : null,
        };
    }

    private function collection(SupportsCollections $provider, string $ref, string $locale): FetchedSet
    {
        $collection = $provider->collection($ref, $locale);

        return new FetchedSet(
            $collection->name,
            array_map(fn (MediaSummary $part): SetEntry => new SetEntry($part), $collection->parts),
            $collection->posterPath,
        );
    }

    private function filmography(SupportsPersonPage $provider, string $code, string $ref, string $locale): FetchedSet
    {
        // A set is fetched once for every viewer of the shelf.
        $person = $provider->person($ref, $locale, MatureChoices::shared()->for($code));

        return new FetchedSet(
            $person->name,
            array_map(fn (PersonCredit $credit): SetEntry => new SetEntry($credit->media, $credit->department, $credit->role, $credit->billing), $person->credits),
            $person->profilePath,
        );
    }

    /**
     * The top of a chart, read page by page until it is long enough or the
     * provider has no more.
     */
    private function chart(SupportsCharts $provider, string $ref, string $locale): ?FetchedSet
    {
        $chart = ChartRef::parse($ref);

        if ($chart === null || ! in_array($chart->type, $provider->chartedTypes(), true)) {
            return null;
        }

        $entries = [];
        $page = 1;

        do {
            $listing = $provider->chart($chart->type, $chart->order, $chart->fromYear, $chart->toYear, $locale, $page);

            foreach ($listing->items as $title) {
                $entries[] = new SetEntry($title);
            }
        } while (count($entries) < $chart->size && $listing->items !== [] && $page++ < $listing->totalPages);

        return new FetchedSet($ref, array_slice($entries, 0, $chart->size));
    }
}
