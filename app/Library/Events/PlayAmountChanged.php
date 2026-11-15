<?php

namespace App\Library\Events;

use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The recorded cost of one closed attempt was corrected (games: hours of that
 * playthrough). Not a new attempt — the history entry stays the same one.
 */
final readonly class PlayAmountChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $userId,
        public MutationSource $source,
        public int $itemId,
        public int $rootId,
        public int $playId,
        public ?int $amount,
        public ?int $previous,
    ) {}
}
