<?php

namespace App\Tracking;

use App\Accounts\Preferences\UserPreferences;
use App\Tracking\Data\TrackingFacts;

/**
 * Where tracked titles show up — a column of the tracking matrix, each with
 * its own toggles per row, its own release mode and its own per-platform
 * game option ({@see UserPreferences::matrix()}).
 */
enum Surface: string
{
    case Calendar = 'calendar';
    case Countdowns = 'countdowns';
    case Notifications = 'notifications';

    /**
     * The tracking matrix: does this surface carry the title for the user?
     * The title's row and the user's toggle for that row here — nothing else.
     * A muted or untouched title has no row, so no surface carries it.
     */
    public function carries(UserPreferences $preferences, TrackingFacts $facts): bool
    {
        return in_array($facts->source(), $preferences->matrix($this), true);
    }

    /**
     * Whether a release known only to its month, quarter or year shows here.
     * A countdown can say "2028"; the calendar's days, its iCal feed and the
     * day's notifications need the day itself, and a stand-in would be one
     * the provider never named.
     */
    public function showsApproximateDates(): bool
    {
        return $this === self::Countdowns;
    }
}
