<?php

namespace App\Models;

use Database\Factories\FranchiseMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One film or show of a franchise: which title, and the card it was found with.
 *
 * @property int $id
 * @property string $franchise the outside source's id of the franchise
 * @property string $type
 * @property string $provider
 * @property string $ref
 * @property string $title
 * @property Carbon|null $release_date
 * @property int|null $votes how many rated the title at the provider
 * @property string|null $collection_ref the provider collection it is a part of
 * @property array<string, mixed> $card
 */
class FranchiseMember extends Model
{
    /** @use HasFactory<FranchiseMemberFactory> */
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
            'card' => 'array',
        ];
    }
}
