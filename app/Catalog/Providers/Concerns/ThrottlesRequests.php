<?php

namespace App\Catalog\Providers\Concerns;

use App\Catalog\ProviderBusy;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * Client-side rate limiting shared by the provider HTTP clients. Spread bursts
 * across the limiter's window instead of failing, but cap the wait so sustained
 * cross-worker contention can never hang a synchronous render or job.
 */
trait ThrottlesRequests
{
    /**
     * Block until the named limiter grants a slot, sleeping between attempts.
     * Throws ProviderBusy once $maxWaitSeconds worth of attempts is spent, so
     * a request can never wait unbounded. The wait is counted in
     * attempts rather than read off the wall clock, and sleeps through
     * Laravel's Sleep — a test or a frozen run can fake it.
     */
    protected function throttle(
        string $limiter,
        int $perWindow,
        int $decaySeconds = 1,
        int $sleepMicros = 100_000,
        float $maxWaitSeconds = 10.0,
    ): void {
        $attemptsLeft = (int) ceil($maxWaitSeconds * 1_000_000 / $sleepMicros);

        while (RateLimiter::attempt($limiter, $perWindow, fn () => true, $decaySeconds) === false) {
            if ($attemptsLeft-- <= 0) {
                throw new ProviderBusy("Rate limit wait exceeded for [{$limiter}].", max(1, RateLimiter::availableIn($limiter)));
            }

            Sleep::usleep($sleepMicros);
        }
    }
}
