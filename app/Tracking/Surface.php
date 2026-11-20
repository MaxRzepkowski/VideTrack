<?php

namespace App\Tracking;

use App\Accounts\Preferences\UserPreferences;
use App\Tracking\Data\TrackingFacts;
use LogicException;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where tracked titles show up — a column of the tracking matrix, its rows
 * switched by default and, for a kind of title with sources of its own, by
 * that kind ({@see UserPreferences::matrix()}).
 */
#[TypeScript]
enum Surface: string
{
    case Calendar = 'calendar';
    case Countdowns = 'countdowns';
    case Notifications = 'notifications';

    /**
     * The tracking matrix: does this surface carry the title for the user?
     * The title's row and the user's toggle for that row here, as its kind of
     * title has them — nothing else. A muted or untouched title has no row,
     * so no surface carries it.
     */
    public function carries(UserPreferences $preferences, TrackingFacts $facts): bool
    {
        $source = $facts->source();
        // Facts read for a card leave the kind of title out; they say why a title is tracked, not where.
        $type = $facts->type ?? throw new LogicException('Facts without their kind of title cannot say which surface carries them.');

        return $source !== null && $this->takes($preferences, $source, $type);
    }

    /**
     * The views: the surfaces a title is seen on rather than told about. A
     * kind of title takes its own sources for both at once.
     *
     * @return list<self>
     */
    public static function views(): array
    {
        return [self::Calendar, self::Countdowns];
    }

    public function isView(): bool
    {
        return $this !== self::Notifications;
    }

    /** Whether the user lets one row of the matrix feed this surface, for a kind of title. */
    public function takes(UserPreferences $preferences, TrackingSource $row, ?string $type): bool
    {
        return in_array($row, $preferences->matrix($this, $type), true);
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
