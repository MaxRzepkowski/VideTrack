<?php

namespace App\Library\Events;

use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** A container root is being watched again: a new run, numbered, has begun. */
final readonly class RewatchStarted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $userId,
        public MutationSource $source,
        public int $rootId,
        public int $cycle,
    ) {}
}
