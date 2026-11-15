<?php

namespace App\Catalog\Relations;

/**
 * What the outside source says of a batch of titles, by the id it was asked about.
 */
final readonly class SourceAnswer
{
    /**
     * @param  array<string, list<RelatedWork>>  $ties  the works tied to each title
     * @param  array<string, list<string>>  $franchises  the source's ids of the franchises each title belongs to
     */
    public function __construct(
        public array $ties = [],
        public array $franchises = [],
    ) {}
}
