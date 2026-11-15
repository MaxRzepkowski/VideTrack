<?php

namespace App\Jobs;

use App\Actions\Media\SyncAirSchedule;
use App\Models\MediaItem;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Backfills a show's episode air times from the air schedule off the request
 * path. Dispatched on a show's first persist and by the daily metadata
 * refresh, so the show page render never waits on the schedule and the
 * calendar gets exact broadcast instants as they become available. A show
 * already waiting for its pass is not queued a second time.
 */
class EnrichShowSchedule implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    /** The schedule answers "too many" under load; a later try usually gets through. */
    public int $tries = 3;

    /** @var list<int> seconds before the second and the third try */
    public array $backoff = [60, 600];

    /** A worker that died mid-pass frees the show after this long. */
    public int $uniqueFor = 3600;

    public function __construct(private readonly int $showId) {}

    public function uniqueId(): string
    {
        return (string) $this->showId;
    }

    public function handle(SyncAirSchedule $sync): void
    {
        $show = MediaItem::query()->find($this->showId);

        if ($show !== null) {
            $sync->execute($show);
        }
    }

    /**
     * No task run tracks a single show, so the log is where a show left
     * without air times is seen. The message is not repeated there: a
     * client's can carry the request address.
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('Air times of a show were not refreshed.', ['show' => $this->showId, 'error' => $exception::class]);
    }
}
