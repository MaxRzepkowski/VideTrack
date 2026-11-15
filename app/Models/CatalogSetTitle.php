<?php

namespace App\Models;

use App\Catalog\Department;
use Database\Factories\CatalogSetTitleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One member of a set: which title, and the card the provider listed it with.
 *
 * @property int $id
 * @property int $catalog_set_id
 * @property int $position
 * @property string $type
 * @property string $provider
 * @property string $ref
 * @property string $title
 * @property Carbon|null $release_date
 * @property int|null $votes how many rated the title at the provider
 * @property Department|null $department what the set's subject did on it, where the set is a person's work
 * @property string|null $role
 * @property int|null $billing the person's place in the cast, the lead first
 * @property array<string, mixed> $card
 */
class CatalogSetTitle extends Model
{
    /** @use HasFactory<CatalogSetTitleFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'department' => Department::class,
            'card' => 'array',
        ];
    }
}
