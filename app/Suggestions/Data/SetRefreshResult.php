<?php

namespace App\Suggestions\Data;

/**
 * What one run of the set refresh did.
 */
final readonly class SetRefreshResult
{
    /**
     * @param  int  $due  sets whose copy was missing or aged when the run began
     * @param  int  $fetched  of those, copied from their provider
     * @param  int  $gone  no longer at their provider, forgotten
     * @param  int  $forgotten  dropped because nobody asked for them in a long while
     * @param  list<string>  $errors  per provider that stopped answering
     */
    public function __construct(
        public int $due,
        public int $fetched,
        public int $gone,
        public int $forgotten,
        public array $errors,
    ) {}
}
