<?php

namespace App\Users\Statistics;

/** What a title ended a statistics period as: the user's last mark on it then, or none yet. */
enum TitleMark: string
{
    case Completed = 'completed';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Dropped = 'dropped';
}
