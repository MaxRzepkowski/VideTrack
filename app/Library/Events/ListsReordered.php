<?php

namespace App\Library\Events;

use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** The user put their lists in a new order. */
final readonly class ListsReordered implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $userId,
        public MutationSource $source,
    ) {}
}
