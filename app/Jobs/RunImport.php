<?php

namespace App\Jobs;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\ExpireConnection;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportInputKind;
use App\Integrations\Import\ImportRunner;
use App\Integrations\Import\ImportSourceRegistry;
use App\Jobs\Concerns\RunsAsTask;
use App\Library\MutationContext;
use App\Library\MutationSource;
use App\Models\ServiceConnection;
use App\Models\TaskRun;
use App\Models\User;
use App\Support\ExternalServiceException;
use App\Support\NotFoundAtService;
use App\Support\ServiceRejected;
use App\Tasks\ProgressReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * One import run, whatever the source, on the queue: the ImportRunner reads
 * and writes, this job holds the task run (progress and the outcome land in
 * task_runs under import-{code}), the source the run is stamped with, and
 * the uploaded file until the run is over.
 */
class RunImport implements ShouldQueue
{
    use Dispatchable, Queueable, RunsAsTask;

    /**
     * A cold import resolves every show tree at the provider, which takes
     * far longer than the 60-second default. queue.database.retry_after
     * must stay above this, or a slow import gets re-delivered mid-run.
     */
    public const TIMEOUT_SECONDS = 3600;

    public int $timeout = self::TIMEOUT_SECONDS;

    /**
     * One retry, so an import killed mid-run (worker restart during a
     * deploy) resumes on its own; re-running is safe because every insert
     * is idempotent.
     */
    public int $maxExceptions = 2;

    public function __construct(
        private readonly TaskRun $taskRun,
        private readonly string $source,
        private readonly ImportInput $input,
        private readonly ImportSelection $selection,
    ) {}

    public static function queueTask(User $user, string $source, ImportInput $input, ImportSelection $selection): void
    {
        self::dispatch(TaskRun::queueTask(ImportSourceRegistry::taskName($source), $user->id), $source, $input, $selection);
    }

    public function handle(
        ImportSourceRegistry $sources,
        ImportRunner $runner,
        MutationContext $mutations,
        ExpireConnection $expire,
    ): void {
        // Everything this job writes is stamped as an import, so consumers can
        // tell a restored account from the user acting title by title.
        try {
            $mutations->run(
                MutationSource::import($this->source),
                fn () => $this->import($sources, $runner),
            );
        } catch (ConnectionExpired $e) {
            // An answered refusal: a retry would only be refused again.
            // The message is a translation key: the run's summary reads it in
            // the user's language, the connection card in the viewer's.
            $this->taskRun->markFailure($e->wording($this->locale()));
            $connection = $this->connection();

            if ($connection !== null) {
                $expire->execute($connection, $e->getMessage());
            }

            $this->discardInput();
        } catch (ServiceRejected|NotFoundAtService $e) {
            // Answered as well: asking again would get the same answer, so
            // the run ends here instead of being retried.
            $reason = $e->wording($this->locale());
            $this->taskRun->markFailure($reason);
            $this->connection()?->update(['last_error' => $reason]);
            $this->discardInput();
        }
    }

    private function import(ImportSourceRegistry $sources, ImportRunner $runner): void
    {
        $this->taskRun->markStarted();

        // The input is discarded once the run is over, never on the way out of
        // an exception: the retry needs the file. failed() discards it after
        // the last attempt.
        $user = $this->taskRun->user()->firstOrFail();

        if ($this->input->kind === ImportInputKind::File && ! is_file((string) $this->input->path)) {
            $this->taskRun->markFailure(__('import.run_file_gone', [], $user->preferredLocale()));

            return;
        }

        $outcome = $runner->run($user, $sources->get($this->source), $this->input, $this->selection, new ProgressReporter($this->taskRun));

        if (! $outcome->finished) {
            $this->taskRun->markFailure($outcome->summary);
            $this->connection()?->update(['last_error' => $outcome->summary]);
            $this->discardInput();

            return;
        }

        $this->taskRun->markSuccess($outcome->summary, $outcome->errors);

        // A run through a connection ends by recording where it stopped, and
        // the next run starts there.
        $this->connection()?->update([
            'state' => $outcome->readPosition,
            'last_imported_at' => now(),
            'last_error' => null,
        ]);

        $this->discardInput();
    }

    /** As every task job words it (RunsAsTask): a raw exception's message stays in the log. */
    public function failed(Throwable $exception): void
    {
        $reason = $exception instanceof ExternalServiceException
            ? $exception->wording($this->locale())
            : __(self::UNEXPECTED_FAILURE, [], $this->locale());

        $this->taskRun->markFailureIfOpen($reason);
        $this->connection()?->update(['last_error' => $reason]);
        $this->discardInput();
    }

    /** The connection the run reads through, while it still exists. */
    private function connection(): ?ServiceConnection
    {
        return $this->input->connectionId === null ? null : ServiceConnection::query()->find($this->input->connectionId);
    }

    /** The language the run reports in: its user's. */
    private function locale(): string
    {
        return $this->taskRun->user()->firstOrFail()->preferredLocale();
    }

    private function discardInput(): void
    {
        if ($this->input->kind === ImportInputKind::File) {
            File::delete((string) $this->input->path);
        }
    }
}
