<?php

namespace App\Library\Events;

use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** One of the user's own lists is gone, with everything that was on it. */
final readonly class ListDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $userId,
        public MutationSource $source,
        public int $listId,
    ) {}
}
