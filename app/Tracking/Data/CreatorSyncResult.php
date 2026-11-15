<?php

namespace App\Tracking\Data;

/** What one pass over a followed creator changed. */
final readonly class CreatorSyncResult
{
    /**
     * @param  list<string>  $errors  titles (or the creator) the provider could not serve
     */
    public function __construct(
        public int $created = 0,
        public int $removed = 0,
        public array $errors = [],
    ) {}
}
