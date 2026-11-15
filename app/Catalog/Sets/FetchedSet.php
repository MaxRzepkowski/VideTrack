<?php

namespace App\Catalog\Sets;

/**
 * A set as its provider lists it now.
 */
final readonly class FetchedSet
{
    /**
     * @param  list<SetEntry>  $entries  in the provider's order
     * @param  string|null  $posterPath  the set's own picture (a collection's poster, a person's photo)
     */
    public function __construct(
        public string $name,
        public array $entries,
        public ?string $posterPath = null,
    ) {}
}
