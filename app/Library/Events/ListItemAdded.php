<?php

namespace App\Library\Events;

use App\Library\Data\ListTarget;
use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * One entry appended to one of the user's lists. Materialized collection
 * lists stay silent: those rows are not user intent.
 */
final readonly class ListItemAdded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $userId,
        public MutationSource $source,
        public int $listId,
        public ListTarget $target,
    ) {}
}
