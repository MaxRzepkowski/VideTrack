<?php

namespace App\Integrations\MangaBaka;

/** Where a series stands in a MangaBaka library (`entry.state`). */
enum MangaBakaState: string
{
    case Reading = 'reading';
    case Rereading = 'rereading';
    case Completed = 'completed';
    case Paused = 'paused';
    case Dropped = 'dropped';
    case PlanToRead = 'plan_to_read';
    case Considering = 'considering';
}
