<?php

namespace App\Models;

use Database\Factories\RecentViewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a user's browsing history (see the recent_views migration). The
 * snapshot columns let the "Recently viewed" strip render on their own.
 */
class RecentView extends Model
{
    /** @use HasFactory<RecentViewFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
