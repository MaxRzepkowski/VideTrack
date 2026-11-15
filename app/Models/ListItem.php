<?php

namespace App\Models;

use App\Catalog\Creators\CreatorKind;
use Database\Factories\ListItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A list position targeting a media item, a person OR a company (custom lists
 * only for people / companies — the backlog means "intend to consume").
 *
 * @property int $id
 * @property int $list_id
 * @property int|null $media_item_id
 * @property int|null $person_id
 * @property int|null $company_id
 * @property int $position
 * @property Carbon|null $created_at
 * @property-read MediaItem|null $mediaItem
 * @property-read Person|null $person
 * @property-read Company|null $company
 */
class ListItem extends Model
{
    /** @use HasFactory<ListItemFactory> */
    use HasFactory;

    protected $guarded = [];

    /** The column a creator of this kind sits in; a collection goes on no list. */
    public static function creatorColumn(CreatorKind $kind): ?string
    {
        return match ($kind) {
            CreatorKind::Person => 'person_id',
            CreatorKind::Company, CreatorKind::Network => 'company_id',
            CreatorKind::Collection => null,
        };
    }

    /**
     * @return BelongsTo<MediaList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(MediaList::class, 'list_id');
    }

    /**
     * @return BelongsTo<MediaItem, $this>
     */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
