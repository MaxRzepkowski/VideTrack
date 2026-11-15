<?php

namespace App\Events\Media;

use App\Notifications\MediaEventPayload;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An external fact about a tracked title reached one user — a premiere, a
 * moved date, a new season, a title gone from its provider. Not a change the
 * user made, so it names no source of one; it fires next to the in-app
 * notification, after deduplication, so other channels can carry the same
 * fact without re-deriving it.
 */
final readonly class MediaEventDetected implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $userId,
        public MediaEventPayload $event,
    ) {}
}
