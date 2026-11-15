<?php

namespace App\Models;

use Database\Factories\WatchCycleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An explicit viewing cycle for a container root. The initial cycle is
 * implicit and represented by a null watch_cycle_id on its plays.
 *
 * @property int $id
 * @property int $user_id
 * @property int $media_item_id
 * @property int $number
 * @property Carbon $started_at
 * @property-read MediaItem $mediaItem
 */
class WatchCycle extends Model
{
    /** @use HasFactory<WatchCycleFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'started_at' => 'datetime',
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
     * @return HasMany<Play, $this>
     */
    public function plays(): HasMany
    {
        return $this->hasMany(Play::class);
    }
}
