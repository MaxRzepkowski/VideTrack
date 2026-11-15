<?php

namespace App\Library\Events;

use App\Library\Data\ListTarget;
use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** One entry taken off one of the user's lists. */
final readonly class ListItemRemoved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $userId,
        public MutationSource $source,
        public int $listId,
        public ListTarget $target,
    ) {}
}
