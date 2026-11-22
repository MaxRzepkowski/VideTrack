<?php

namespace App\Catalog\Relations;

use App\Catalog\Contracts\SupportsSummaryLookup;
use App\Catalog\Data\CollectionLink;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\ResolvedMedia;
use App\Catalog\ExternalIdResolver;
use App\Catalog\ItemMetadata;
use App\Catalog\ItemNotFoundException;
use App\Catalog\MediaItemSummaries;
use App\Catalog\MediaKind;
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
        private BookByTitle $books,
    ) {}

    /**
     * Each work in our catalogue: from the library when it is stored, else
     * from its provider; a book known by no id, by its title and authors.
     * Nothing for a work the catalogue lacks, one gone from its provider,
     * and adult material. The stored ones are looked up together, a query per
     * media type, however many works are asked about. So are the foreign ids
     * and the cards of works not stored, where their provider answers about
     * many at once; elsewhere each costs a request of its own.
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
        $idsByKind = [];

        foreach ($works as $work) {
            $idsByKind[$work->kind->value][] = $work->externalIds;
        }

        foreach ($idsByKind as $kind => $idBags) {
            $this->resolver->prefetch($idBags, MediaKind::from($kind));
        }

        foreach ($works as $key => $work) {
            $media = $this->resolver->resolve($work->externalIds, $work->kind, $locale) ?? $this->byTitle($work, $locale);

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

        $cards = $this->cardsOfUnstored($refsByType, $stored, $locale);
        $found = [];

        foreach ($works as $key => $work) {
            $media = $resolved[$key] ?? null;
            $found[$key] = $media === null ? null : $this->card($media, $stored[MediaSummary::keyOf($media->type, $media->ref)] ?? null, $cards, $locale);
        }

        return $found;
    }

    /**
     * The cards of the works not stored, for each type whose provider gives
     * many in one request: type => ref => card, a ref it does not have left out.
     *
     * @param  array<string, array<array-key, true>>  $refsByType
     * @param  array<string, MediaItem>  $stored
     * @return array<string, array<array-key, MediaSummary>>
     *
     * @throws ExternalServiceException
     */
    private function cardsOfUnstored(array $refsByType, array $stored, string $locale): array
    {
        $cards = [];

        foreach ($refsByType as $type => $refs) {
            $provider = $this->providers->forType($this->types->get($type));

            if (! $provider instanceof SupportsSummaryLookup) {
                continue;
            }

            $unstored = array_values(array_filter(array_map(strval(...), array_keys($refs)), fn (string $ref): bool => ! isset($stored[MediaSummary::keyOf($type, $ref)])));
            $cards[$type] = $unstored === [] ? [] : $provider->summaries($type, $unstored, $locale);
        }

        return $cards;
    }

    /**
     * @throws ExternalServiceException
     */
    private function byTitle(CatalogWork $work, string $locale): ?ResolvedMedia
    {
        if ($work->kind !== MediaKind::Book || $work->title === null) {
            return null;
        }

        $book = $this->books->find($work->title, $work->authors, $locale);

        return $book === null ? null : new ResolvedMedia($book->type, $book->ref);
    }

    /**
     * @param  array<string, array<array-key, MediaSummary>>  $cards  of the works not stored, by type, where asked together
     * @return array{card: MediaSummary, collection: CollectionLink|null, votes: int|null}|null
     */
    private function card(ResolvedMedia $media, ?MediaItem $stored, array $cards, string $locale): ?array
    {
        if ($stored !== null) {
            return $stored->gone_at !== null ? null : [
                'card' => MediaItemSummaries::summary($stored),
                'collection' => ItemMetadata::fromStored($stored->metadata)->collection,
                'votes' => null,
            ];
        }

        // A card asked for together comes without its collection: only a
        // fellow title of a franchise is read against one, and franchises
        // are listed of films and shows.
        if (isset($cards[$media->type])) {
            $card = $cards[$media->type][$media->ref] ?? null;

            return $card === null ? null : ['card' => $card, 'collection' => null, 'votes' => $card->votes ?? 0];
        }

        try {
            $details = $this->providers->forType($this->types->get($media->type))->details($media->type, $media->ref, $locale);
        } catch (ItemNotFoundException) {
            return null;
        }

        // Never rated at its provider is no reach at all; a type whose
        // provider rates nothing (comics) has no bar for it to clear.
        return $details->adult ? null : ['card' => $details->summary, 'collection' => $details->collection, 'votes' => $details->summary->votes ?? 0];
    }
}
