<?php

namespace App\Library\Data;

use App\Library\EpisodeState;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A run of consecutive aired episodes sharing one state, in airing order:
 * run-length encoded so a 1213-episode show costs a handful of entries. The
 * progress bar expands them back into one tick per episode.
 */
#[TypeScript]
final readonly class EpisodeSegment
{
    public function __construct(
        /** Watched is a completed play; skipped is settled without being seen (its own entry or a skipped season). */
        public EpisodeState $state,
        public int $episodes,
    ) {}
}
