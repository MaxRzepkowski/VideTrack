<?php

namespace App\Tasks\Liveness;

/** The background processes the instance cannot do without, each leaving a sign it is alive. */
enum BeatingProcess: string
{
    /** Queues the recurring tasks: without it nothing refreshes and nothing is sent. */
    case Scheduler = 'scheduler';
    /** Runs what is queued. */
    case Worker = 'worker';
}
