<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How far one provider's list of changes for one type has been read.
 *
 * @property string $provider
 * @property string $type
 * @property Carbon $checked_until
 */
class ChangeCursor extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['checked_until' => 'datetime'];
    }
}
