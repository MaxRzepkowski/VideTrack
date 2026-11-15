<?php

namespace App\Jobs;

use App\Actions\Media\SyncCreatorTitles;
use App\Catalog\Creators\CreatorTable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One pass right after a person or company is followed, so its upcoming
 * titles reach the calendar within seconds instead of the next morning —
 * the daily sweep stays the retry. A follow, an unfollow and a follow again
 * while the first pass still waits ask once.
 */
class SyncFollowedCreator implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    /** A worker that died mid-pass frees the creator after this long. */
    public int $uniqueFor = 3600;

    public function __construct(
        private readonly CreatorTable $table,
        private readonly int $creatorId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->table->value}:{$this->creatorId}";
    }

    public function handle(SyncCreatorTitles $sync): void
    {
        $creator = $this->table->model()::query()->find($this->creatorId);

        if ($creator === null) {
            return;
        }

        $result = $sync->execute($creator);

        if ($result->errors !== []) {
            Log::warning('Followed-creator sync had errors.', ['creator' => $creator->name, 'errors' => $result->errors]);
        }
    }
}
