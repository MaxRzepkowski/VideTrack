<?php

namespace App\Models;

use App\Catalog\Relations\TitleRelation;
use Database\Factories\RelatedTitleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A title tied to a stored one (its spin-off, what comes next, the novel it
 * is based on): which title, how it stands to ours, and the card it was found with.
 *
 * @property int $id
 * @property int $media_item_id
 * @property TitleRelation $relation
 * @property string $type
 * @property string $provider
 * @property string $ref
 * @property string $title
 * @property Carbon|null $release_date
 * @property array<string, mixed> $card
 * @property int|null $votes how many rated the title at the provider; null where it is stored here
 * @property-read MediaItem $mediaItem
 */
class RelatedTitle extends Model
{
    /** @use HasFactory<RelatedTitleFactory> */
    use HasFactory;

    protected $table = 'title_relations';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation' => TitleRelation::class,
            'release_date' => 'date',
            'card' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MediaItem, $this>
     */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
