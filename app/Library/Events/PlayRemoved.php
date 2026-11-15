<?php

namespace App\Library\Events;

use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * History entries deleted — unmarking a position, dropping a single play from
 * the plays panel, or undoing a drop.
 */
final readonly class PlayRemoved implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<int>  $playIds
     */
    public function __construct(
        public int $userId,
        public MutationSource $source,
        public int $itemId,
        public int $rootId,
        public array $playIds,
    ) {}
}
