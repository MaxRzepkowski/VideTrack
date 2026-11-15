<?php

namespace App\Models;

use App\Catalog\ExternalIdSource;
use App\Catalog\MediaKind;
use Database\Factories\ExternalIdMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * "This IMDb id is that TMDB movie" — resolved once, kept forever. Mappings
 * are locale-independent (they are refs, not titles) and shared by every
 * consumer: scrobble sources and imports.
 *
 * @property int $id
 * @property ExternalIdSource $source
 * @property string $external_ref
 * @property MediaKind $media_kind what the id was asked about as
 * @property string $type our type code
 * @property string $provider
 * @property string $provider_ref
 */
class ExternalIdMapping extends Model
{
    /** @use HasFactory<ExternalIdMappingFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ExternalIdSource::class,
            'media_kind' => MediaKind::class,
        ];
    }
}
