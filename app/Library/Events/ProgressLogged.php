<?php

namespace App\Library\Events;

use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Session progress written to the open attempt. Hitting a positional type's
 * maximum finishes the title instead, and that path emits PlayRecorded alone.
 */
final readonly class ProgressLogged implements ShouldDispatchAfterCommit
{
    /**
     * @param  int|null  $added  increment of this session, null for an absolute edit
     * @param  int|null  $set  absolute correction, null for a session
     * @param  int  $progress  counter after the write, in the type's progress unit
     */
    public function __construct(
        public int $userId,
        public MutationSource $source,
        public int $itemId,
        public ?int $added,
        public ?int $set,
        public int $progress,
    ) {}
}
