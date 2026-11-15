<?php

namespace App\Library\Data;

use App\Models\Follow;
use App\Tracking\TrackingSource;

/**
 * What a user put on a set of positions directly — ratings, favourites, list
 * memberships and the bell — each a single fact of the pair, read as it is.
 * Together with the history's part they make the state a card reads.
 */
final readonly class PositionMarks
{
    /**
     * @param  array<int, int>  $ratings  item id => rating
     * @param  array<int, true>  $favorites  ids of the favourite items
     * @param  array<int, list<int>>  $listIds  item id => the user's lists holding it
     * @param  array<int, Follow>  $follows  item id => the bell's exception on it
     * @param  array<int, TrackingSource|null>  $followSources  root id => the tracking matrix's row it falls in
     */
    public function __construct(
        public array $ratings,
        public array $favorites,
        public array $listIds,
        public array $follows,
        public array $followSources,
    ) {}

    public function stateOf(int $itemId, HistoryState $history): UserMediaState
    {
        $names = ($this->follows[$itemId] ?? null)?->sponsorNames();

        return new UserMediaState(
            watched: $history->watched,
            watchedAt: $history->watchedAt?->toIso8601String(),
            droppedAt: $history->droppedAt?->toIso8601String(),
            plays: $history->plays,
            cycle: $history->cycle,
            completedCycles: $history->completedCycles,
            rating: $this->ratings[$itemId] ?? null,
            favorite: isset($this->favorites[$itemId]),
            listIds: $this->listIds[$itemId] ?? [],
            follow: ($this->follows[$itemId] ?? null)?->mode,
            followVia: $names === null || $names === '' ? null : $names,
            followSource: $this->followSources[$itemId] ?? null,
            status: $history->status,
            rootStatus: $history->rootStatus,
            statusInherited: $history->statusInherited,
            progress: $history->progress,
            playedAmount: $history->playedAmount,
            droppedAmount: $history->droppedAmount,
            latestDropped: $history->latestDropped,
            watchedEpisodes: $history->watchedEpisodes,
            airedEpisodes: $history->airedEpisodes,
            remainingEpisodes: $history->remainingEpisodes,
            skippedEpisodes: $history->skippedEpisodes,
            remainingMinutes: $history->remainingMinutes,
            watchedMinutes: $history->watchedMinutes,
            position: $history->position,
            nextEpisode: $history->nextEpisode,
            inProgress: $history->inProgress,
            progression: $history->progression,
        );
    }
}
