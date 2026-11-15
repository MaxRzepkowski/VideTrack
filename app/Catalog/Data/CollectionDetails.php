<?php

namespace App\Catalog\Data;

/**
 * A provider-curated collection of titles (e.g. a TMDB movie saga) —
 * materialized locally as an ownerless system list.
 */
final readonly class CollectionDetails
{
    public function __construct(
        public string $ref,
        public string $name,
        public ?string $overview = null,
        public ?string $posterPath = null,
        public ?string $backdropPath = null,
        /** @var list<MediaSummary> in the collection's order: release order for a saga, the numbering for a book series */
        public array $parts = [],
        /** @var list<string> gallery backdrops, as a title's are */
        public array $backdrops = [],
        /** @var list<string> gallery posters */
        public array $posters = [],
        /** What the collection is tied to beside its parts (its series, subseries, spin-offs), where the provider names it. */
        public ?CollectionRelations $relations = null,
    ) {}
}
