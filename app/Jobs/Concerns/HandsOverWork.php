<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\OneRunAtATime;

/**
 * A run that goes on in another job: the next job is queued only once this
 * one has let go of the run's lock ({@see OneRunAtATime}),
 * so it never finds its own run still holding it.
 */
interface HandsOverWork
{
    /** The job that carries the run on; null once the run is done. */
    public function handOver(): ?object;
}
