<?php

namespace App\Library;

use App\Models\Play;
use App\Models\User;
use App\Support\Numbers;
use Illuminate\Database\Eloquent\Builder;

/**
 * The entries one source brings at once — one import, one restored backup.
 * They tell the source's picture together: a file listing a show's episodes
 * and that the show is dropped says what the show is now, so the episodes it
 * brings do not refuse that drop. What the library held before the batch
 * began is history the source's status is weighed against.
 */
final readonly class BackfillBatch
{
    private function __construct(private int $lastPlayId) {}

    public static function begin(User $user): self
    {
        return new self(Numbers::int(Play::query()->where('user_id', $user->id)->max('id')) ?? 0);
    }

    /**
     * @param  Builder<Play>  $plays
     * @return Builder<Play>
     */
    public function heldBefore(Builder $plays): Builder
    {
        return $plays->where('id', '<=', $this->lastPlayId);
    }
}
