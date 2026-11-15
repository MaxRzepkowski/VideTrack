<?php

namespace App\Library\Events;

use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** The run under way is gone, with every entry logged in it; the one before it is current again. */
final readonly class RewatchCancelled implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $userId,
        public MutationSource $source,
        public int $rootId,
        public int $cycle,
    ) {}
}
