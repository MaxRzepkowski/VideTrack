<?php

namespace App\Library\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The user's projection (library_entries) now reads differently: written
 * after a batch, when a release aired, by a rebuild, or wiped. Announced once
 * the rows are in, so whatever is worked out from the projection is worked
 * out again from the new rows, not from the ones they replaced. Not a write
 * of the user's own; the write behind it, if any, announced itself.
 */
final readonly class LibraryProjected implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $userId,
    ) {}
}
