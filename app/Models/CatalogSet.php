<?php

namespace App\Models;

use App\Catalog\Sets\SetKey;
use App\Catalog\Sets\SetKind;
use Database\Factories\CatalogSetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A provider's set of titles in one language: what it is, when someone last
 * needed it and when the provider last listed it.
 *
 * @property int $id
 * @property SetKind $kind
 * @property string $provider
 * @property string $ref
 * @property string $locale
 * @property string|null $name
 * @property string|null $poster_path the set's own picture (a collection's poster), where it has one
 * @property Carbon $wanted_at
 * @property Carbon|null $fetched_at never listed yet when null
 */
class CatalogSet extends Model
{
    /** @use HasFactory<CatalogSetFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SetKind::class,
            'wanted_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    public function key(): SetKey
    {
        return new SetKey($this->kind, $this->provider, $this->ref);
    }

    /**
     * @return HasMany<CatalogSetTitle, $this>
     */
    public function titles(): HasMany
    {
        return $this->hasMany(CatalogSetTitle::class)->orderBy('position');
    }
}
