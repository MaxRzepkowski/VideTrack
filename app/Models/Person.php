<?php

namespace App\Models;

use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $provider
 * @property string $provider_ref
 * @property string $name
 * @property string|null $profile_path
 * @property string|null $biography
 * @property Carbon|null $birthday
 * @property Carbon|null $deathday
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $refreshed_at
 */
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'deathday' => 'date',
            'metadata' => 'array',
            'refreshed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Credit, $this>
     */
    public function credits(): HasMany
    {
        return $this->hasMany(Credit::class);
    }
}
