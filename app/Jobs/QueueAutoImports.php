<?php

namespace App\Jobs;

use App\Enums\TaskRunStatus;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Import\Contracts\ReadsFromConnection;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Jobs\Concerns\RunsAsTask;
use App\Models\ServiceConnection;
use App\Models\TaskRun;
use App\Tasks\TaskName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Every quarter hour: queues an import for every connection with
 * auto-import on whose source is due (each source sets its own pace). Data only comes in — the
 * connections read, nothing is written back.
 *
 * A connection the service let go of (expired) waits for the user; one
 * whose run is still queued or running is not given a second.
 */
class QueueAutoImports implements ShouldQueue
{
    use Dispatchable, Queueable, RunsAsTask;

    // The worker's default, stated: the overlap lock lapses with it.
    public int $timeout = 60;

    public int $maxExceptions = self::BREAKS_ALLOWED;

    /**
     * Started a few minutes early so a source whose last run took a minute
     * does not slip to the following check.
     */
    private const SLACK_MINUTES = 5;

    public function __construct(private readonly TaskRun $taskRun) {}

    public static function queueTask(): void
    {
        self::dispatch(TaskRun::queueTask(TaskName::AutoImport->value));
    }

    public function handle(ImportSourceRegistry $sources): void
    {
        $this->taskRun->markStarted();

        $queued = 0;

        foreach ($sources->all() as $source) {
            if (! $source instanceof ReadsFromConnection) {
                continue;
            }

            $due = now()->subMinutes(max(1, $source->autoImportMinutes() - self::SLACK_MINUTES));

            $connections = ServiceConnection::query()
                ->where('service', $source->connectionService())
                ->where('auto_import', true)
                ->where('status', ConnectionStatus::Active)
                ->where(fn ($query) => $query->whereNull('last_imported_at')->orWhere('last_imported_at', '<=', $due))
                ->with('user')
                ->get();

            foreach ($connections as $connection) {
                if ($this->inFlight($connection->user_id, $source->code())) {
                    continue;
                }

                RunImport::queueTask($connection->user, $source->code(), ImportInput::connection($connection->id), ImportSelection::everything());
                $queued++;
            }
        }

        $this->taskRun->markSuccess(__('tasks.summary_auto_imports', ['count' => $queued]));
    }

    private function inFlight(int $userId, string $code): bool
    {
        return TaskRun::query()
            ->where('user_id', $userId)
            ->where('name', ImportSourceRegistry::taskName($code))
            ->whereIn('status', [TaskRunStatus::Pending, TaskRunStatus::Started])
            // A row a killed worker left behind never blocks the connection
            // for good: past twice the run's time limit it is not in flight.
            ->where('queued_at', '>', now()->subSeconds(2 * RunImport::TIMEOUT_SECONDS))
            ->exists();
    }
}
