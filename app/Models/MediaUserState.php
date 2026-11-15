<?php

namespace App\Models;

use App\Enums\MediaUserStatus;
use Database\Factories\MediaUserStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * State of the OPEN attempt on a root title (on hold / manually in
 * progress). On hold removes the title from Up Next and the calendar; the
 * play history stays intact. Flat progress types (games) also accumulate
 * their session progress here until a completion or a drop absorbs it —
 * closed attempts are play rows with a PlayOutcome, never states.
 *
 * @property int $id
 * @property int $user_id
 * @property int $media_item_id
 * @property MediaUserStatus $status
 * @property int|null $progress accumulated session progress in the type's unit (games: minutes)
 * @property-read MediaItem $mediaItem
 */
class MediaUserState extends Model
{
    /** @use HasFactory<MediaUserStateFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MediaUserStatus::class,
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
}
