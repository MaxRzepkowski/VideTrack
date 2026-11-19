<?php

namespace App\Models;

use App\Enums\PlayOutcome;
use App\Library\MutationSource;
use Database\Factories\PlayFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One CLOSED attempt at a position (movie / episode / game / show root):
 * completed = a full viewing / playthrough, dropped = an abandoned attempt.
 * The play log is the only source of truth for history; "watched" derives
 * from completed plays alone, while ANY play resolves the position for
 * Up Next (a skipped episode is deliberately settled, not pending).
 *
 * @property int $id
 * @property int $user_id
 * @property int $media_item_id
 * @property int|null $watch_cycle_id null denotes the implicit initial cycle
 * @property Carbon $watched_at moment the attempt closed
 * @property PlayOutcome $outcome
 * @property int|null $amount real cost of the attempt in the type's progress unit (games: minutes)
 * @property string|null $source where the entry came in from ({@see MutationSource::value()}); null when that was never kept
 * @property-read MediaItem $mediaItem
 */
class Play extends Model
{
    /** @use HasFactory<PlayFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'watch_cycle_id' => 'integer',
            'watched_at' => 'datetime',
            'outcome' => PlayOutcome::class,
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MediaItem, $this>
     */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /**
     * @return BelongsTo<WatchCycle, $this>
     */
    public function watchCycle(): BelongsTo
    {
        return $this->belongsTo(WatchCycle::class);
    }

    /**
     * Cycle one is implicit and carries no row of its own.
     */
    public function cycleNumber(): int
    {
        return $this->watchCycle->number ?? 1;
    }

    /**
     * @param  Builder<Play>  $query
     * @return Builder<Play>
     */
    public function scopeInCycle(Builder $query, ?int $cycleId): Builder
    {
        return $cycleId === null
            ? $query->whereNull('watch_cycle_id')
            : $query->where('watch_cycle_id', $cycleId);
    }

    /**
     * One user's entries on one position, newest first.
     *
     * @param  Builder<Play>  $query
     * @return Builder<Play>
     */
    public function scopeHistoryOf(Builder $query, User $user, MediaItem $item): Builder
    {
        return $query->where('user_id', $user->id)->where('media_item_id', $item->id)->orderByDesc('watched_at');
    }
}
