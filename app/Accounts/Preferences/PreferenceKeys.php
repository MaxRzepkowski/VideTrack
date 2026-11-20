<?php

namespace App\Accounts\Preferences;

use App\Catalog\RelatedReleaseKind;
use App\Tracking\Surface;
use App\Tracking\TrackingSource;

/**
 * The names a user's settings are stored under, where a setting comes in a
 * family (a row of the tracking matrix on each surface, an option of a kind
 * of title, an event that can be switched off): what {@see UserPreferences}
 * reads them by, and what the settings page names them by.
 */
final class PreferenceKeys
{
    /** The setting a surface's release mode is kept under. */
    public static function releaseMode(Surface $surface): string
    {
        return self::surface($surface).'_release_mode';
    }

    /** The setting that lists each platform's premiere as its own entry. */
    public static function perPlatform(Surface $surface): string
    {
        return self::surface($surface).'_game_platforms';
    }

    /** The setting the providers' events on a view are kept under. */
    public static function events(Surface $surface): string
    {
        return self::surface($surface).'_events';
    }

    /** The setting a tracked game's releases of their own of one kind are kept under. */
    public static function related(Surface $surface, RelatedReleaseKind $kind): string
    {
        return self::surface($surface).'_game_'.$kind->value;
    }

    /** The setting a kind of notification is switched under. */
    public static function notifies(NotificationEvent $event): string
    {
        return 'notify_'.$event->value;
    }

    /** The setting a row of the tracking matrix on a surface is kept under. */
    public static function matrix(Surface $surface, TrackingSource $row): string
    {
        return self::surface($surface).'_'.self::row($row);
    }

    private static function surface(Surface $surface): string
    {
        return match ($surface) {
            Surface::Calendar => 'calendar',
            Surface::Countdowns => 'countdown',
            Surface::Notifications => 'notifications',
        };
    }

    private static function row(TrackingSource $row): string
    {
        return match ($row) {
            TrackingSource::OnHold => 'on_hold',
            TrackingSource::Dropped => 'dropped',
            TrackingSource::Backlog => 'watchlist',
            TrackingSource::Activity => 'active',
            TrackingSource::ManualFollow => 'followed',
            TrackingSource::CreatorFollow => 'creators',
        };
    }
}
