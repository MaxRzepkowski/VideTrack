<?php

namespace App\Jobs;

use App\Actions\Media\AnnounceArchivedTitles;
use App\Actions\Media\RefreshDueTitles;
use App\Jobs\Concerns\HandsOverWork;
use App\Jobs\Concerns\RunsAsTask;
use App\Models\MediaItem;
use App\Models\TaskRun;
use App\Tasks\CatalogRefresh\RefreshQueue;
use App\Tasks\CatalogRefresh\RefreshReason;
use App\Tasks\CatalogRefresh\RefreshTally;
use App\Tasks\ProgressReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One stretch of the background refresh. It works through the queue for a
 * few minutes, then hands the rest to the next batch, so imports and
 * scrobbles get the worker in between, the worker may restart, and a run cut
 * short anywhere loses nothing: what was not asked still waits in the queue.
 * When only providers asked to be waited out are left, the next batch is
 * queued for when the first of them may be asked again.
 */
class RefreshCatalogBatch implements HandsOverWork, ShouldQueue
{
    use Dispatchable, Queueable, RunsAsTask;

    /** Seconds of asking a batch takes on, well inside its timeout. */
    public const WORK_SECONDS = 240;

    public int $timeout = 600;

    public int $maxExceptions = self::BREAKS_ALLOWED;

    private ?self $nextBatch = null;

    public function __construct(
        private readonly TaskRun $taskRun,
        private readonly RefreshTally $tally,
    ) {}

    public function handle(RefreshDueTitles $refresh, RefreshQueue $queue, AnnounceArchivedTitles $announceArchived): void
    {
        $progress = new ProgressReporter($this->taskRun);
        $tally = $refresh->execute($this->tally, now()->addSeconds(self::WORK_SECONDS), $progress);
        $now = now()->getTimestamp();

        // Between batches the worker serves the rest of the queue, so the
        // page says the run is waiting rather than stuck on its last title.
        if ($queue->hasDue($tally->skippedAt($now))) {
            $this->nextBatch = new self($this->taskRun, $tally);
            $progress->reportNow(__('tasks.progress_waiting_in_queue', ['done' => $tally->processed, 'total' => $tally->total()]));

            return;
        }

        $wake = $tally->nextWakeAfter($now);

        if ($wake !== null && $queue->hasDue($tally->stopped)) {
            $this->nextBatch = (new self($this->taskRun, $tally))->delay($wake - $now);
            $progress->reportNow(__('tasks.progress_waiting_for_provider', [
                'done' => $tally->processed,
                'total' => $tally->total(),
                'minutes' => (int) ceil(($wake - $now) / 60),
                'providers' => implode(', ', array_diff($tally->skippedAt($now), $tally->stopped)),
            ]));

            return;
        }

        $tally = $tally->withNotified($announceArchived->execute($this->archivedTitles($tally)));

        // Gone titles are listed too, so an operator sees exactly which
        // vanished upstream, not just how many; so are the titles that took
        // the most memory. None of it went wrong.
        $this->taskRun->markSuccess($this->summary($tally), $tally->errors, [...$tally->removed, ...$tally->kept, ...$tally->heavy, ...$tally->observations]);
    }

    public function handOver(): ?object
    {
        return $this->nextBatch;
    }

    /**
     * @return array<int, list<MediaItem>>
     */
    private function archivedTitles(RefreshTally $tally): array
    {
        $titles = MediaItem::query()->whereIn('id', array_merge([], ...array_values($tally->archived)))->get()->keyBy('id');
        $archived = [];

        foreach ($tally->archived as $userId => $rootIds) {
            $archived[$userId] = array_values(array_filter(array_map(fn (int $id): ?MediaItem => $titles->get($id), $rootIds)));
        }

        return $archived;
    }

    private function summary(RefreshTally $tally): string
    {
        $reasons = [];

        foreach (RefreshReason::cases() as $reason) {
            $planned = $tally->planned[$reason->value] ?? 0;

            if ($planned > 0) {
                $reasons[] = ($tally->refreshed[$reason->value] ?? 0)." of {$planned} {$reason->value}";
            }
        }

        $summary = "Refreshed {$tally->refreshedCount()} of {$tally->total()} titles".($reasons === [] ? '.' : ' ('.implode(', ', $reasons).').');

        if ($tally->notified > 0) {
            $summary .= " Sent {$tally->notified} notifications.";
        }

        if ($tally->removed !== []) {
            $summary .= ' Removed '.count($tally->removed).' gone from the provider.';
        }

        if ($tally->kept !== []) {
            $summary .= ' '.count($tally->kept).' kept (still referenced).';
        }

        foreach ($tally->measuredTypes as $type) {
            $changed = $tally->changed[$type] ?? 0;
            $summary .= ' The list of changes named '.($tally->changedNamed[$type] ?? 0)." of {$changed} {$type} titles that changed.";
        }

        if ($tally->stopped !== []) {
            $summary .= ' Stopped asking '.implode(', ', $tally->stopped).' for this run.';
        }

        return $summary;
    }
}
