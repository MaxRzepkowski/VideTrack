<?php

namespace App\Actions\Media;

use App\Catalog\Contracts\MetadataProvider;
use App\Catalog\Contracts\SupportsCollections;
use App\Catalog\Data\CollectionBannerData;
use App\Catalog\Data\CollectionDetails;
use App\Catalog\Data\MediaDetails;
use App\Catalog\ProviderCalls;

/**
 * Banner payload for the collection a title belongs to (a film saga, a game
 * or book series). The ref/name come free with the details; the part count needs the
 * (cached) collection fetch — a failure there only drops the count, never the
 * banner or page.
 */
final readonly class ResolveTitleCollection
{
    public function execute(MetadataProvider $provider, MediaDetails $details, string $locale): ?CollectionBannerData
    {
        $collection = $details->collection;

        if ($collection === null || ! $provider instanceof SupportsCollections) {
            return null;
        }

        // Without the collection the banner still shows; only the part count stays unknown.
        $full = (new ProviderCalls)->attempt(fn (): CollectionDetails => $provider->collection($collection->ref, $locale), null);
        $partCount = $full === null ? null : count($full->parts);
        $backdropPath = $full->backdropPath ?? $collection->backdropPath ?? $collection->posterPath;

        return new CollectionBannerData(
            provider: $provider->code(),
            ref: $collection->ref,
            name: $collection->name,
            backdropPath: $backdropPath,
            partCount: $partCount,
            partType: $details->summary->type,
        );
    }
}
