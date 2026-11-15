<?php

namespace App\Listeners;

use App\Events\Media\TitlePersisted;
use App\Jobs\EnrichShowSchedule;

/**
 * A show new to the library gets its episode broadcast times from the air
 * schedule in the background, so the calendar has exact instants without the
 * request that stored the show waiting on another service.
 */
final class BackfillAirTimesOfNewShow
{
    public function handle(TitlePersisted $event): void
    {
        if ($event->type === 'show') {
            EnrichShowSchedule::dispatch($event->itemId);
        }
    }
}
