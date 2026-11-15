<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A followable creator entity that lives outside the media type registry: a
 * game studio / publisher (IGDB) or a TV network (TMDB), discriminated by
 * `kind`. The company analogue of {@see Person} — keyed by (provider, kind,
 * provider_ref), persisted lazily on the user's first follow.
 *
 * @property int $id
 * @property string $provider
 * @property string $kind
 * @property string $provider_ref
 * @property string $name
 * @property string|null $logo_path
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $refreshed_at
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'refreshed_at' => 'datetime',
        ];
    }
}
