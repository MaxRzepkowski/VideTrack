<?php

namespace App\Tracking;

/**
 * One thing a user left on a title or a part of it, as the tracking matrix
 * reads it.
 */
enum Trace: string
{
    case Activity = 'activity';
    case Backlog = 'backlog';
    case OnHold = 'on_hold';
    case Dropped = 'dropped';
    case Mute = 'mute';
    case ManualFollow = 'manual_follow';
    case CreatorFollow = 'creator_follow';

    /** Something logged: a play, a status, a drop — the title is in the user's history. */
    public function isActivity(): bool
    {
        return $this === self::Activity || $this === self::OnHold || $this === self::Dropped;
    }
}
