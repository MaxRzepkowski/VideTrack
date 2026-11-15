<?php

namespace App\Jobs\Middleware;

use App\Jobs\Concerns\HandsOverWork;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * One run of a task at a time. A run that finds its twin at work leaves a
 * fresh copy of itself for later and ends, instead of being released: a
 * release spends one of the worker's tries, and waiting is not failing — the
 * tries stay for what they are for, a run that breaks or outlives its
 * timeout. The lock lapses with the job's timeout, so a killed worker frees it.
 * A run split over several jobs hands the next one over once the lock is free.
 */
final readonly class OneRunAtATime
{
    /**
     * @param  Closure(): object  $later  the copy to queue for later: without the
     *                                    queue entry this run came in on, which holds
     *                                    the worker's connection and is deleted when
     *                                    this run ends
     */
    public function __construct(
        public string $key,
        public int $expiresAfterSeconds,
        private int $recheckAfterSeconds,
        private Closure $later,
    ) {}

    public function handle(object $job, Closure $next): void
    {
        $lock = Cache::lock(self::lockName($this->key), $this->expiresAfterSeconds);

        if (! $lock->get()) {
            dispatch(($this->later)())->delay($this->recheckAfterSeconds);

            return;
        }

        try {
            $next($job);
        } finally {
            $lock->release();
        }

        // Past the release: the next part of the run takes the same lock.
        if ($job instanceof HandsOverWork && ($following = $job->handOver()) !== null) {
            dispatch($following);
        }
    }

    /** The lock a run under this key holds while it works. */
    public static function lockName(string $key): string
    {
        return "task-run:{$key}";
    }
}
