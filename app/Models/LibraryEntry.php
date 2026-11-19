<?php

namespace App\Models;

use App\Enums\PositionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a user stands with one position of a title they have history on, as
 * the library projection last worked it out from that history. A shortcut,
 * never the truth: `vide:library:rebuild` makes it again.
 *
 * @property int $id
 * @property int $user_id
 * @property int $media_item_id
 * @property int $root_id
 * @property bool $watched
 * @property CarbonImmutable|null $watched_at
 * @property CarbonImmutable|null $dropped_at
 * @property int $plays
 * @property int $cycle
 * @property int $completed_cycles
 * @property PositionStatus|null $status
 * @property PositionStatus|null $root_status
 * @property bool $status_inherited
 * @property int|null $progress
 * @property int|null $played_amount
 * @property int|null $dropped_amount
 * @property bool $latest_dropped
 * @property int|null $watched_episodes
 * @property int|null $aired_episodes
 * @property int|null $remaining_episodes
 * @property int|null $skipped_episodes
 * @property int|null $remaining_minutes
 * @property int|null $watched_minutes
 * @property int|null $position
 * @property int|null $next_episode_id
 * @property bool $in_progress
 * @property bool $progression
 * @property list<array{state: string, episodes: int}>|null $segments
 * @property bool $played
 * @property CarbonImmutable|null $last_played_at
 * @property-read MediaItem $mediaItem
 * @property-read MediaItem $root
 */
class LibraryEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'watched' => 'boolean',
            'watched_at' => 'immutable_datetime',
            'dropped_at' => 'immutable_datetime',
            'status' => PositionStatus::class,
            'root_status' => PositionStatus::class,
            'status_inherited' => 'boolean',
            'latest_dropped' => 'boolean',
            'in_progress' => 'boolean',
            'progression' => 'boolean',
            'segments' => 'array',
            'played' => 'boolean',
            'last_played_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MediaItem, $this>
     */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /**
     * @return BelongsTo<MediaItem, $this>
     */
    public function root(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'root_id');
    }

    /**
     * The entries of the roots themselves: one per title.
     *
     * @param  Builder<LibraryEntry>  $query
     */
    public function scopeOfRoots(Builder $query): void
    {
        $query->whereColumn('media_item_id', 'root_id');
    }

    /**
     * Positions finished at least once, in any run — unlike
     * {@see scopeFinishedTitlesOf()}, a show watched again counts while the
     * new run is under way.
     *
     * @param  Builder<LibraryEntry>  $query
     */
    public function scopeEverFinished(Builder $query): void
    {
        $query->where('library_entries.completed_cycles', '>', 0);
    }

    /**
     * The titles a user has finished: their root entries marked watched.
     * Select `media_item_id` from it to name the titles.
     *
     * @param  Builder<LibraryEntry>  $query
     */
    public function scopeFinishedTitlesOf(Builder $query, User $user): void
    {
        $query->ofRoots()->where('user_id', $user->id)->where('watched', true);
    }
}
