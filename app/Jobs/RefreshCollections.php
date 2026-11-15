<?php

namespace App\Jobs;

use App\Actions\Media\DeleteCollection;
use App\Actions\Media\RefreshCollection;
use App\Catalog\Contracts\SupportsCollections;
use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderRegistry;
use App\Jobs\Concerns\RunsAsTask;
use App\Models\MediaList;
use App\Models\TaskRun;
use App\Support\ExternalServiceException;
use App\Tasks\ProgressReporter;
use App\Tasks\TaskName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-syncs every materialized provider collection: title,
 * description and parts — collections grow when new installments release.
 * Reports to `task_runs`.
 */
class RefreshCollections implements ShouldQueue
{
    use Dispatchable, Queueable, RunsAsTask;

    /** A cold sync re-fetches every collection at the provider — well past the 60s default. */
    public int $timeout = 1800;

    public int $maxExceptions = self::BREAKS_ALLOWED;

    public function __construct(private readonly TaskRun $taskRun) {}

    /** Creates the pending task_runs record and queues the job. */
    public static function queueTask(): void
    {
        self::dispatch(TaskRun::queueTask(TaskName::RefreshCollections->value));
    }

    public function handle(ProviderRegistry $providers, RefreshCollection $refresh, DeleteCollection $delete): void
    {
        $this->taskRun->markStarted();
        $progress = new ProgressReporter($this->taskRun);

        $lists = MediaList::query()->collections()->get();

        $total = $lists->count();
        $locale = config()->string('tracker.default_locale');
        $refreshed = 0;
        $notified = 0;
        $removed = [];
        $errors = [];

        foreach ($lists->values() as $index => $list) {
            $source = $list->collectionSource();

            if ($source === null) {
                continue;
            }

            try {
                $provider = $providers->get($source['provider']);

                if (! $provider instanceof SupportsCollections) {
                    continue;
                }

                $notified += $refresh->execute($source['provider'], $provider->collection($source['ref'], $locale), $locale);
                $refreshed++;
            } catch (ItemNotFoundException) {
                // The collection no longer exists at the provider (404).
                // Reported as removed, not an error.
                $delete->execute($list);
                $removed[] = "{$list->title}: removed (no longer at the provider).";
            } catch (ExternalServiceException $e) {
                $errors[] = "{$list->title}: {$e->wording()}";
            }

            $progress->report(fn (): string => __('tasks.progress_syncing_collection', ['current' => $index + 1, 'total' => $total]));
        }

        $summary = "Refreshed {$refreshed} of {$total} collections.";

        if ($notified > 0) {
            $summary .= " Sent {$notified} new-part notifications.";
        }

        if ($removed !== []) {
            $summary .= ' Removed '.count($removed).' no longer at the provider.';
        }

        $this->taskRun->markSuccess($summary, $errors, $removed);
    }
}
