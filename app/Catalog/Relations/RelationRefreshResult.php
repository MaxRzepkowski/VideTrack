<?php

namespace App\Catalog\Relations;

/**
 * What one run of asking the outside source about the stored titles did.
 */
final readonly class RelationRefreshResult
{
    /**
     * @param  int  $due  titles never asked about, or long enough ago, when the run began
     * @param  int  $asked  of those, asked about
     * @param  int  $ties  ties kept for them
     * @param  int  $listed  titles kept from the franchises they named
     * @param  list<string>  $errors  what a service that stopped answering said, worded for a reader
     */
    public function __construct(
        public int $due,
        public int $asked,
        public int $ties,
        public int $listed,
        public array $errors,
    ) {}
}
