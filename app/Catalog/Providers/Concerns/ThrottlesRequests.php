<?php

namespace App\Catalog\Providers\Concerns;

use App\Catalog\ProviderBusy;
use App\Support\Numbers;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * Client-side rate limiting shared by the provider HTTP clients: at most
 * $perWindow requests in any $windowSeconds, wherever the window falls. A
 * window counted from a fixed start lets a full window's worth through at the
 * end of one and again at the start of the next, twice the limit within the
 * rolling count a service keeps. So the moments of the last requests are
 * kept, and a request past the limit books the moment the oldest of them
 * leaves the window, then sleeps until it. Booking happens under a lock:
 * every process asking the same service (the worker, a page, a webhook)
 * shares one count, where a read-then-write count lets two of them take the
 * same last slot. A wait longer than the caller can afford books nothing and
 * fails as the service being busy, so a request never hangs unbounded.
 */
trait ThrottlesRequests
{
    /** How long a request waits for its moment unless its client says otherwise. */
    public const RATE_WINDOW_MAX_WAIT_SECONDS = 10.0;

    /** Booking is a few cache reads; a lock held longer than this belongs to a process that died. */
    private const RATE_WINDOW_LOCK_SECONDS = 5;

    protected function throttle(string $limiter, int $perWindow, int $windowSeconds = 1, float $maxWaitSeconds = self::RATE_WINDOW_MAX_WAIT_SECONDS): void
    {
        $waitMs = $this->bookRequest($limiter, $perWindow, $windowSeconds * 1000, (int) round($maxWaitSeconds * 1000));

        if ($waitMs > 0) {
            Sleep::usleep($waitMs * 1000);
        }
    }

    /**
     * @return int milliseconds until the booked moment
     *
     * @throws ProviderBusy the wait would outlast $maxWaitMs, or other processes held the count that long
     */
    private function bookRequest(string $limiter, int $perWindow, int $windowMs, int $maxWaitMs): int
    {
        $busy = "Rate limit wait exceeded for [{$limiter}].";
        $lock = Cache::lock("rate-window-lock:{$limiter}", self::RATE_WINDOW_LOCK_SECONDS);

        try {
            $lock->block(max(1, (int) ceil($maxWaitMs / 1000)));
        } catch (LockTimeoutException) {
            throw new ProviderBusy($busy, 1);
        }

        try {
            $key = "rate-window:{$limiter}";
            $now = now()->getTimestampMs();
            $booked = array_values(array_filter(Numbers::ints(Cache::array($key, [])), fn (int $at): bool => $at > $now - $windowMs));
            sort($booked);

            // Past the limit, the request goes when the one $perWindow back leaves the window.
            $at = count($booked) < $perWindow ? $now : $booked[count($booked) - $perWindow] + $windowMs;

            if ($at - $now > $maxWaitMs) {
                throw new ProviderBusy($busy, max(1, (int) ceil(($at - $now) / 1000)));
            }

            $booked[] = $at;
            Cache::put($key, $booked, (int) ceil(($at - $now + $windowMs) / 1000) + 1);

            return $at - $now;
        } finally {
            $lock->release();
        }
    }
}
