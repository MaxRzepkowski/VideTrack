<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An event a user took off their calendar and their "live" button.
 *
 * @property int $id
 * @property int $user_id
 * @property int $catalog_event_id
 */
class HiddenEvent extends Model
{
    protected $guarded = [];
}
