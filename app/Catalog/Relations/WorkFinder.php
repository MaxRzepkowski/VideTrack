<?php

namespace App\Catalog\Relations;

use App\Catalog\Data\CollectionLink;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\ResolvedMedia;
use App\Catalog\ExternalIdResolver;
use App\Catalog\ItemMetadata;
use App\Catalog\ItemNotFoundException;
use App\Catalog\MediaItemSummaries;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use App\Models\MediaItem;
use App\Support\ExternalServiceException;

/**
 * Finds a work an outside source names in our catalogue, as a card.
 */
final readonly class WorkFinder
{
    /** Stored titles looked up in one query. */
    private const CHUNK = 500;

    public function __construct(
        private ExternalIdResolver $resolver,
        private MediaTypeRegistry $types,
        private ProviderRegistry $providers,
    ) {}

    /**
     * Each work in our catalogue: from the library when it is stored, else
     * from its provider. Nothing for a work the catalogue lacks, one gone
     * from its provider, and adult material. The stored ones are looked up
     * together, a query per media type, however many works are asked about;
     * one not stored still costs a request to its provider.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, CatalogWork>  $works
     * @return array<TKey, array{card: MediaSummary, collection: CollectionLink|null, votes: int|null}|null> `votes` is null for a stored title: it is the user's own, whatever its reach
     *
     * @throws ExternalServiceException the provider did not answer
     */
    public function findAll(array $works, string $locale): array
    {
        $resolved = [];
        $refsByType = [];

        foreach ($works as $key => $work) {
            $media = $this->resolver->resolve($work->externalIds, $work->kind, $locale);

            if ($media !== null && $this->types->has($media->type)) {
                $resolved[$key] = $media;
                $refsByType[$media->type][$media->ref] = true;
            }
        }

        $stored = [];

        foreach ($refsByType as $type => $refs) {
            foreach (array_chunk(array_map(strval(...), array_keys($refs)), self::CHUNK) as $chunk) {
                foreach (MediaItem::query()->where('type', $type)->whereIn('provider_ref', $chunk)->whereNull('parent_id')->get() as $item) {
                    $stored[MediaSummary::keyOf($item->type, $item->provider_ref)] = $item;
                }
            }
        }

        $found = [];

        foreach ($works as $key => $work) {
            $media = $resolved[$key] ?? null;
            $found[$key] = $media === null ? null : $this->card($media, $stored[MediaSummary::keyOf($media->type, $media->ref)] ?? null, $locale);
        }

        return $found;
    }

    /**
     * @return array{card: MediaSummary, collection: CollectionLink|null, votes: int|null}|null
     */
    private function card(ResolvedMedia $media, ?MediaItem $stored, string $locale): ?array
    {
        if ($stored !== null) {
            return $stored->gone_at !== null ? null : [
                'card' => MediaItemSummaries::summary($stored),
                'collection' => ItemMetadata::fromStored($stored->metadata)->collection,
                'votes' => null,
            ];
        }

        try {
            $details = $this->providers->forType($this->types->get($media->type))->details($media->type, $media->ref, $locale);
        } catch (ItemNotFoundException) {
            return null;
        }

        return $details->adult ? null : ['card' => $details->summary, 'collection' => $details->collection, 'votes' => $details->summary->votes ?? 0];
    }
}
