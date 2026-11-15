<?php

namespace App\Library\Events;

use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** The user made a list of their own. */
final readonly class ListCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $userId,
        public MutationSource $source,
        public int $listId,
    ) {}
}
