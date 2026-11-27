<?php

namespace App\Tasks\Maintenance;

use App\Enums\TaskRunStatus;
use App\Jobs\RunMaintenanceChecks;
use App\Models\MaintenanceCheckState;
use App\Models\TaskRun;
use App\Tasks\Data\TaskLine;
use App\Tasks\Maintenance\Contracts\MaintenanceCheck;
use App\Tasks\RunFailure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runs the maintenance checks and remembers where each stands. A run leaves
 * its outcome in the check's own row; the task history hears only of a run
 * that noted something or broke, so a quiet check every quarter of an hour
 * adds nothing to it.
 */
final readonly class MaintenanceChecks
{
    public function __construct(private MaintenanceRegistry $registry) {}

    /** The task history name of a check's runs, its one spelling. */
    public static function historyName(string $code): string
    {
        return "check-{$code}";
    }

    /**
     * The window's checks, queued as one run that takes them in their order.
     * Like a scheduled task, a window queues even while an earlier run is at
     * work: a check skips a twin still at it rather than wait for it.
     */
    public function queueWindow(MaintenanceWindow $window): void
    {
        $codes = array_keys($this->registry->applicable($window));

        if ($codes !== []) {
            dispatch(new RunMaintenanceChecks($codes));
        }
    }

    /**
     * One run of each check, in the order given, under the run's id.
     *
     * @param  list<string>  $codes
     */
    public function perform(string $runId, array $codes): void
    {
        $checks = array_intersect_key($this->registry->applicable(), array_flip($codes));

        // Queued before their module was switched off: nothing is left to look after.
        MaintenanceCheckState::query()->whereIn('code', array_diff($codes, array_keys($checks)))->delete();

        $states = MaintenanceCheckState::query()
            ->whereIn('code', array_keys($checks))
            ->get()
            ->keyBy(fn (MaintenanceCheckState $state): string => $state->code);

        foreach ($codes as $code) {
            $check = $checks[$code] ?? null;

            if ($check === null) {
                continue;
            }

            $lock = Cache::lock(self::lockName($code), RunMaintenanceChecks::TIMEOUT_SECONDS);

            // Another run of the same check (two windows' runs overlapping)
            // is at work: it answers for both.
            if (! $lock->get()) {
                continue;
            }

            try {
                $this->performOne($states->get($code) ?? new MaintenanceCheckState(['code' => $code]), $runId, $check);
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * A run the worker gave up on (killed with its timeout or with the
     * worker): the check it left at work ends as broken, in its row and in
     * the history. Only its own: a check another run has taken since is that
     * run's to end. Static, because a failed job is handed no container to
     * build this service with.
     */
    public static function abandon(string $runId): void
    {
        $states = MaintenanceCheckState::query()
            ->where('run_id', $runId)
            ->whereIn('status', [TaskRunStatus::Pending, TaskRunStatus::Started])
            ->get();

        foreach ($states as $state) {
            $state->fill([
                'status' => TaskRunStatus::Failure,
                'error' => RunFailure::unexpected()->stored(),
                'checked_at' => now(),
            ])->save();

            self::remember($state->code, RunFailure::unexpected(), [], failed: true);
        }
    }

    /**
     * The windows a check of which has not run since the window was last
     * due — the scheduler slept through it (a host off at night, a late
     * start). A check queued or at work is on its way already.
     *
     * @return list<MaintenanceWindow>
     */
    public function overdueWindows(Carbon $now): array
    {
        $states = MaintenanceCheckState::query()->get()->keyBy(fn (MaintenanceCheckState $state): string => $state->code);
        $overdue = [];

        foreach (MaintenanceWindow::cases() as $window) {
            $due = $window->previousRunAt($now);

            foreach (array_keys($this->registry->applicable($window)) as $code) {
                $state = $states->get($code);

                if ($state === null || (! $state->isInFlight() && ($state->checked_at === null || $state->checked_at->lt($due)))) {
                    $overdue[] = $window;

                    break;
                }
            }
        }

        return $overdue;
    }

    private function performOne(MaintenanceCheckState $state, string $runId, MaintenanceCheck $check): void
    {
        $state->fill(['status' => TaskRunStatus::Started, 'run_id' => $runId, 'started_at' => now()])->save();
        $report = new CheckReport;
        $error = null;

        // One check breaking must not take the others with it: its row says
        // it broke, the log has the rest.
        try {
            $check->run($report);
        } catch (Throwable $e) {
            report($e);
            $error = RunFailure::of($e);
        }

        $state->fill([
            'status' => $error === null ? TaskRunStatus::Success : TaskRunStatus::Failure,
            'result' => array_map(fn (TaskLine $line): array => $line->stored(), $report->results()),
            'error' => $error?->stored(),
            'checked_at' => now(),
        ])->save();

        $notes = $report->notes();

        if ($error !== null) {
            self::remember($state->code, $error, $notes, failed: true);
        } elseif ($notes !== []) {
            self::remember($state->code, $notes[0], array_slice($notes, 1), failed: false);
        }
    }

    /**
     * A run worth the task history, finished as it ended.
     *
     * @param  list<TaskLine>  $details
     */
    private static function remember(string $code, TaskLine $summary, array $details, bool $failed): void
    {
        $noted = array_map(fn (TaskLine $line): string => $line->wording(), $details);
        $run = TaskRun::queueTask(self::historyName($code));
        $run->markStarted();

        if ($failed) {
            $run->markFailure($summary->wording(), details: $noted);
        } else {
            $run->markSuccess($summary->wording(), details: $noted);
        }
    }

    /** The lock a run of the check holds while it works. */
    public static function lockName(string $code): string
    {
        return "maintenance-check:{$code}";
    }
}
