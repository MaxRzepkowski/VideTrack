<?php

namespace App\Models;

use App\Tasks\CatalogRefresh\RefreshReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where one root stands with the background refresh: when its provider last
 * answered for it and since when it waits to be asked again.
 *
 * @property int $media_item_id
 * @property Carbon|null $asked_at
 * @property Carbon|null $due_at
 * @property RefreshReason|null $reason
 * @property-read MediaItem $item
 */
class TitleRefresh extends Model
{
    protected $primaryKey = 'media_item_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'asked_at' => 'datetime',
            'due_at' => 'datetime',
            'reason' => RefreshReason::class,
        ];
    }

    /**
     * @return BelongsTo<MediaItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'media_item_id');
    }
}
