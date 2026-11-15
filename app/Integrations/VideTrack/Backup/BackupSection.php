<?php

namespace App\Integrations\VideTrack\Backup;

/**
 * The sections of a backup, in the order the file holds them and a restore
 * reads them: a run's start comes before the viewings in it.
 */
enum BackupSection: string
{
    case Cycles = 'cycles';
    case Plays = 'plays';
    case Ratings = 'ratings';
    case Favorites = 'favorites';
    case Statuses = 'statuses';
    case Lists = 'lists';
    case Follows = 'follows';

    /** How the import page names the section. */
    public function label(): string
    {
        return match ($this) {
            self::Cycles => __('import.backup_cycles'),
            self::Follows => __('import.backup_follows'),
            self::Plays => __('common.history'),
            self::Ratings => __('common.ratings'),
            self::Favorites => __('common.favorites'),
            self::Statuses => __('import.backup_statuses'),
            self::Lists => __('common.lists'),
        };
    }
}
