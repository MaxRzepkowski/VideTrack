<?php

namespace App\Tasks\Attention;

use App\Models\TaskRun;
use App\Tasks\Attention\Contracts\AttentionSource;
use App\Tasks\Attention\Data\AttentionItem;
use App\Tasks\Attention\Data\PageVisit;
use App\Tasks\Data\TaskLine;
use App\Tasks\Data\WordedLine;
use App\Tasks\Liveness\BeatingProcess;
use App\Tasks\Liveness\Heartbeat;
use App\Tasks\Maintenance\CheckLevel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The scheduler or the worker gone quiet, read from the signs they leave, so
 * the answer does not wait on the scheduler it is about. A process with no
 * fresh sign is counted only on evidence the instance has been up long
 * enough to have one: a fresh instance is not a broken one.
 */
final readonly class StalledProcesses implements AttentionSource
{
    /** The scheduler ticks every minute; this long without a sign, it is not running. */
    public const SCHEDULER_QUIET_SECONDS = 300;

    /** How long a queued job may wait before a quiet worker counts as stopped. */
    public const WORKER_QUIET_SECONDS = 900;

    public function __construct(private Heartbeat $heartbeat) {}

    public function items(PageVisit $visit): array
    {
        $items = [];
        $scheduler = $this->heartbeat->lastBeat(BeatingProcess::Scheduler);
        $worker = $this->heartbeat->lastBeat(BeatingProcess::Worker);

        if (! $this->fresh($scheduler, self::SCHEDULER_QUIET_SECONDS) && $this->upForAWhile()) {
            $items[] = $this->item('tasks.the_scheduler_is_not_running', 'tasks.nothing_refreshes_or_is_sent', $scheduler);
        }

        if (! $this->fresh($worker, self::WORKER_QUIET_SECONDS) && $this->jobWaiting() && ! $this->jobAtWork()) {
            $items[] = $this->item('tasks.the_worker_is_not_running', 'tasks.queued_work_waits', $worker);
        }

        return $items;
    }

    private function fresh(?CarbonImmutable $beat, int $quietSeconds): bool
    {
        return $beat !== null && $beat->diffInSeconds(now()) < $quietSeconds;
    }

    /** A task run queued long enough ago for the scheduler to have ticked since: the container's start queues one. */
    private function upForAWhile(): bool
    {
        return TaskRun::query()->where('queued_at', '<=', now()->subSeconds(self::SCHEDULER_QUIET_SECONDS))->exists();
    }

    private function item(string $title, string $consequence, ?CarbonImmutable $lastBeat): AttentionItem
    {
        return new AttentionItem(CheckLevel::Problem, __($title), [
            WordedLine::of(new TaskLine($consequence)),
            ...($lastBeat === null ? [] : [WordedLine::of(new TaskLine('tasks.last_sign_of_life_at', moments: ['time' => $lastBeat]))]),
            WordedLine::of(new TaskLine('tasks.restart_the_container')),
        ], $lastBeat?->toIso8601String());
    }

    /** A job ready to run that nobody took for longer than a working worker would leave it. */
    private function jobWaiting(): bool
    {
        return $this->jobs()?->whereNull('reserved_at')->where('available_at', '<=', now()->subSeconds(self::WORKER_QUIET_SECONDS)->getTimestamp())->exists() ?? false;
    }

    /**
     * A job the worker took within its retry window: a long one keeps it
     * from looping, and is no sign it died. One reserved longer ago was left
     * by a worker that did.
     */
    private function jobAtWork(): bool
    {
        return $this->jobs()?->where('reserved_at', '>=', now()->subSeconds(config()->integer('queue.connections.database.retry_after'))->getTimestamp())->exists() ?? false;
    }

    /** The queue's jobs where they can be read: only a database queue keeps them in a table. */
    private function jobs(): ?Builder
    {
        return config('queue.default') === 'database' ? DB::table(config()->string('queue.connections.database.table')) : null;
    }
}
