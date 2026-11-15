<?php

namespace App\Library\Events;

use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A title became a favorite or stopped being one. Emitted on a real change
 * only — the action sets a state rather than toggling it, and a
 * double-clicked heart is not two facts.
 */
final readonly class FavoriteSet implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $userId,
        public MutationSource $source,
        public int $itemId,
        public bool $favorite,
    ) {}
}
