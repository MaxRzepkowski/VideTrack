<?php

namespace App\Catalog\Relations;

use App\Catalog\MediaKind;

/**
 * A work an outside source names, before it is found in our catalogue: what
 * kind of work it is and the ids other catalogues know it by, or, for a book
 * known by none, what to look it up by.
 */
final readonly class CatalogWork
{
    /**
     * @param  array<string, string>  $externalIds  catalogue => id, as the id resolver reads them
     * @param  list<string>  $authors  who wrote it, to tell the book apart from others of its title
     */
    public function __construct(
        public MediaKind $kind,
        public array $externalIds,
        public ?string $title = null,
        public array $authors = [],
    ) {}
}
