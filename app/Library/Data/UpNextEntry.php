<?php

namespace App\Library\Data;

use App\Catalog\Data\MediaSummary;
use App\Catalog\EpisodeBadge;
use App\Library\ClosesRun;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One "Up Next to Watch" card: a show and the first episode the user has not
 * watched yet, with the counters its progress bar reads: the library
 * projection's, which counts every show with a next episode.
 */
#[TypeScript]
final readonly class UpNextEntry
{
    /**
     * @param  list<EpisodeSegment>  $segments  the bar, run by run in airing order
     */
    public function __construct(
        public MediaSummary $show,
        /** Carries its season number and its show's title in `extra`. */
        public MediaSummary $episode,
        /** The season's poster, else the show's. */
        public ?string $poster,
        public ?EpisodeBadge $badge,
        /** Settling this episode leaves nothing more to watch: an ended show, or the last known season of a returning one. */
        public ?ClosesRun $closes,
        public int $watchedEpisodes,
        /** Settled without being watched: the holes in the bar. */
        public int $skippedEpisodes,
        public int $airedEpisodes,
        /** Aired episodes neither watched nor settled by a skip: what is left. */
        public int $remainingEpisodes,
        /** What is left to sit through, and what already was: the bar's hover hint. */
        public int $remainingMinutes,
        public int $watchedMinutes,
        /** Which aired episode of the show the card points at, counted across seasons (1-based). */
        public int $position,
        public array $segments,
    ) {}
}
