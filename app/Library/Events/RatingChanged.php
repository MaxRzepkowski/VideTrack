<?php

namespace App\Library\Events;

use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class RatingChanged implements ShouldDispatchAfterCommit
{
    /**
     * @param  int|null  $previous  the score this one replaced, null when first
     */
    public function __construct(
        public int $userId,
        public MutationSource $source,
        public int $itemId,
        public int $rating,
        public ?int $previous,
    ) {}
}
