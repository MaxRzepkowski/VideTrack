<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One title an event showed: which title, and the card the provider listed it with.
 *
 * @property int $id
 * @property int $catalog_event_id
 * @property int $position
 * @property string $type
 * @property string $provider
 * @property string $ref
 * @property string $title
 * @property Carbon|null $release_date
 * @property int|null $votes
 * @property array<string, mixed> $card
 */
class CatalogEventTitle extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'card' => 'array',
        ];
    }

    /** @return BelongsTo<CatalogEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CatalogEvent::class, 'catalog_event_id');
    }
}
