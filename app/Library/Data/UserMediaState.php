<?php

namespace App\Library\Data;

use App\Enums\FollowMode;
use App\Enums\PositionStatus;
use App\Tracking\TrackingSource;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where the user stands with one position (a title, a season, an episode):
 * what every card, panel and the API read. The defaults are a position the
 * user never touched, which is also what a page falls back on for a card the
 * server sent no state for.
 */
#[TypeScript]
final readonly class UserMediaState implements JsonSerializable
{
    /**
     * @param  list<int>  $listIds
     */
    public function __construct(
        public bool $watched = false,
        /** The most recent play, ISO 8601 with the instance's offset; the client shows it in the viewer's zone. */
        public ?string $watchedAt = null,
        /** The drop that closed the current attempt. */
        public ?string $droppedAt = null,
        /** Completed plays across the whole history; a container sums its leaves'. */
        public int $plays = 0,
        /** The viewing cycle the position is in now; 1 is the implicit first one. */
        public int $cycle = 1,
        /** Cycles that settled every aired episode; a playable counts its completed plays. */
        public int $completedCycles = 0,
        public ?int $rating = null,
        public bool $favorite = false,
        public array $listIds = [],
        /** The bell's exception on a title; null leaves it to the tracking matrix. */
        public ?FollowMode $follow = null,
        /** Who rang the bell for the user — the followed people, companies or collections. */
        public ?string $followVia = null,
        /** The tracking matrix's row the title falls in, which is what the bell names. */
        public ?TrackingSource $followSource = null,
        public ?PositionStatus $status = null,
        /** Containers below a root only: the status their root carries, so a season can say it is dropped WITH its show. */
        public ?PositionStatus $rootStatus = null,
        /**
         * The status shown IS the root's rather than one given to this
         * container: it decides the wording and whether clearing it here
         * reaches the root.
         */
        public bool $statusInherited = false,
        /** The open attempt's progress in the type's unit (games: minutes). */
        public ?int $progress = null,
        /** Playables: what the completions really cost (games: minutes played). */
        public ?int $playedAmount = null,
        /** The progress the current dropped attempt froze at, offered for the merge on reopening it. */
        public ?int $droppedAmount = null,
        /** Playables: the newest entry is a drop, even while an open session masks the status shown. */
        public bool $latestDropped = false,
        public ?int $watchedEpisodes = null,
        public ?int $airedEpisodes = null,
        /** Aired episodes neither watched nor settled by a skip. */
        public ?int $remainingEpisodes = null,
        /** Settled without being watched: the holes in the progress bar. */
        public ?int $skippedEpisodes = null,
        public ?int $remainingMinutes = null,
        public ?int $watchedMinutes = null,
        /** Which aired episode the next one is, counted across seasons from 1. */
        public ?int $position = null,
        public ?EpisodePointer $nextEpisode = null,
        public bool $inProgress = false,
        /** False only where a container tracks no progression of its own (a specials season): its counters stay null. */
        public bool $progression = true,
    ) {}

    public static function untouched(): self
    {
        return new self;
    }

    /**
     * @return array{
     *     watched: bool,
     *     watchedAt: string|null,
     *     droppedAt: string|null,
     *     plays: int,
     *     cycle: int,
     *     completedCycles: int,
     *     rating: int|null,
     *     favorite: bool,
     *     listIds: list<int>,
     *     follow: value-of<FollowMode>|null,
     *     followVia: string|null,
     *     followSource: value-of<TrackingSource>|null,
     *     status: value-of<PositionStatus>|null,
     *     rootStatus: value-of<PositionStatus>|null,
     *     statusInherited: bool,
     *     progress: int|null,
     *     playedAmount: int|null,
     *     droppedAmount: int|null,
     *     latestDropped: bool,
     *     watchedEpisodes: int|null,
     *     airedEpisodes: int|null,
     *     remainingEpisodes: int|null,
     *     skippedEpisodes: int|null,
     *     remainingMinutes: int|null,
     *     watchedMinutes: int|null,
     *     position: int|null,
     *     nextEpisode: EpisodePointer|null,
     *     inProgress: bool,
     *     progression: bool
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'watched' => $this->watched,
            'watchedAt' => $this->watchedAt,
            'droppedAt' => $this->droppedAt,
            'plays' => $this->plays,
            'cycle' => $this->cycle,
            'completedCycles' => $this->completedCycles,
            'rating' => $this->rating,
            'favorite' => $this->favorite,
            'listIds' => $this->listIds,
            'follow' => $this->follow?->value,
            'followVia' => $this->followVia,
            'followSource' => $this->followSource?->value,
            'status' => $this->status?->value,
            'rootStatus' => $this->rootStatus?->value,
            'statusInherited' => $this->statusInherited,
            'progress' => $this->progress,
            'playedAmount' => $this->playedAmount,
            'droppedAmount' => $this->droppedAmount,
            'latestDropped' => $this->latestDropped,
            'watchedEpisodes' => $this->watchedEpisodes,
            'airedEpisodes' => $this->airedEpisodes,
            'remainingEpisodes' => $this->remainingEpisodes,
            'skippedEpisodes' => $this->skippedEpisodes,
            'remainingMinutes' => $this->remainingMinutes,
            'watchedMinutes' => $this->watchedMinutes,
            'position' => $this->position,
            'nextEpisode' => $this->nextEpisode,
            'inProgress' => $this->inProgress,
            'progression' => $this->progression,
        ];
    }
}
