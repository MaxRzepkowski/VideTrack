<?php

namespace App\Library\Events;

use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Everything the user did with the library was wiped at once; ClearUserData names what that covers. */
final readonly class LibraryCleared implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $userId,
        public MutationSource $source,
    ) {}
}
