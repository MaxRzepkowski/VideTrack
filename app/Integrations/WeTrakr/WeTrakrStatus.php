<?php

namespace App\Integrations\WeTrakr;

/**
 * Where a title stands in WeTrakr's tracklog, which mixes the history with
 * the watchlist and the dropped titles. A show watched or being watched
 * adds nothing its watched episodes do not already say.
 */
enum WeTrakrStatus: string
{
    case Watched = 'watched';
    case Watching = 'watching';
    case PlanToWatch = 'plantowatch';
    case Discarded = 'discarded';
}
