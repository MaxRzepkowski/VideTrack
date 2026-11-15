<?php

namespace App\Library\Data;

use App\Enums\PositionStatus;
use App\Library\EpisodeState;
use App\Models\LibraryEntry;
use Carbon\CarbonImmutable;

/**
 * The part of where the user stands with a position that their history
 * decides: plays, drops, viewing cycles, open attempts and the tree below a
 * container. What they put on it directly (a rating, a list, the bell) is
 * not here; {@see PositionMarks} dresses this into the full state.
 */
final readonly class HistoryState
{
    /**
     * @param  list<array{state: EpisodeState, episodes: int}>|null  $segments  containers with a progression: their aired episodes run-length encoded, as the progress bar draws them
     */
    public function __construct(
        public bool $watched = false,
        public ?CarbonImmutable $watchedAt = null,
        public ?CarbonImmutable $droppedAt = null,
        public int $plays = 0,
        public int $cycle = 1,
        public int $completedCycles = 0,
        public ?PositionStatus $status = null,
        public ?PositionStatus $rootStatus = null,
        public bool $statusInherited = false,
        public ?int $progress = null,
        public ?int $playedAmount = null,
        public ?int $droppedAmount = null,
        public bool $latestDropped = false,
        public ?int $watchedEpisodes = null,
        public ?int $airedEpisodes = null,
        public ?int $remainingEpisodes = null,
        public ?int $skippedEpisodes = null,
        public ?int $remainingMinutes = null,
        public ?int $watchedMinutes = null,
        public ?int $position = null,
        public ?int $nextEpisodeId = null,
        public ?EpisodePointer $nextEpisode = null,
        public bool $inProgress = false,
        public bool $progression = true,
        public ?array $segments = null,
    ) {}

    public static function untouched(): self
    {
        return new self;
    }

    /**
     * The state as stored in `library_entries`; the pointer to the next
     * episode is not a column of its own, the reader rebuilds it from the id.
     */
    public static function fromEntry(LibraryEntry $entry, ?EpisodePointer $nextEpisode): self
    {
        return new self(
            watched: $entry->watched,
            watchedAt: $entry->watched_at,
            droppedAt: $entry->dropped_at,
            plays: $entry->plays,
            cycle: $entry->cycle,
            completedCycles: $entry->completed_cycles,
            status: $entry->status,
            rootStatus: $entry->root_status,
            statusInherited: $entry->status_inherited,
            progress: $entry->progress,
            playedAmount: $entry->played_amount,
            droppedAmount: $entry->dropped_amount,
            latestDropped: $entry->latest_dropped,
            watchedEpisodes: $entry->watched_episodes,
            airedEpisodes: $entry->aired_episodes,
            remainingEpisodes: $entry->remaining_episodes,
            skippedEpisodes: $entry->skipped_episodes,
            remainingMinutes: $entry->remaining_minutes,
            watchedMinutes: $entry->watched_minutes,
            position: $entry->position,
            nextEpisodeId: $entry->next_episode_id,
            nextEpisode: $nextEpisode,
            inProgress: $entry->in_progress,
            progression: $entry->progression,
            segments: $entry->segments === null ? null : array_map(
                fn (array $segment): array => ['state' => EpisodeState::from($segment['state']), 'episodes' => $segment['episodes']],
                $entry->segments,
            ),
        );
    }

    /**
     * The columns of `library_entries` this state fills, as the database
     * holds them — so a stored row and a fresh one compare as equal arrays.
     *
     * @return array<string, scalar|null>
     */
    public function toColumns(): array
    {
        return [
            'watched' => $this->watched,
            'watched_at' => $this->watchedAt?->toDateTimeString(),
            'dropped_at' => $this->droppedAt?->toDateTimeString(),
            'plays' => $this->plays,
            'cycle' => $this->cycle,
            'completed_cycles' => $this->completedCycles,
            'status' => $this->status?->value,
            'root_status' => $this->rootStatus?->value,
            'status_inherited' => $this->statusInherited,
            'progress' => $this->progress,
            'played_amount' => $this->playedAmount,
            'dropped_amount' => $this->droppedAmount,
            'latest_dropped' => $this->latestDropped,
            'watched_episodes' => $this->watchedEpisodes,
            'aired_episodes' => $this->airedEpisodes,
            'remaining_episodes' => $this->remainingEpisodes,
            'skipped_episodes' => $this->skippedEpisodes,
            'remaining_minutes' => $this->remainingMinutes,
            'watched_minutes' => $this->watchedMinutes,
            'position' => $this->position,
            'next_episode_id' => $this->nextEpisodeId,
            'in_progress' => $this->inProgress,
            'progression' => $this->progression,
            'segments' => $this->segments === null ? null : json_encode(array_map(
                fn (array $segment): array => ['state' => $segment['state']->value, 'episodes' => $segment['episodes']],
                $this->segments,
            ), JSON_THROW_ON_ERROR),
        ];
    }
}
