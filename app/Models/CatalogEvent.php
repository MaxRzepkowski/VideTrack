<?php

namespace App\Models;

use Database\Factories\CatalogEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A showcase or show of a medium (Summer Game Fest, a Nintendo Direct), as its
 * provider lists it.
 *
 * @property int $id
 * @property string $provider
 * @property string $ref
 * @property string $name
 * @property string|null $description
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $time_zone the zone the provider times it in
 * @property string|null $live_stream_url
 * @property string|null $logo_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CatalogEvent extends Model
{
    /** @use HasFactory<CatalogEventFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return HasMany<CatalogEventTitle, $this> */
    public function titles(): HasMany
    {
        return $this->hasMany(CatalogEventTitle::class)->orderBy('position');
    }
}
