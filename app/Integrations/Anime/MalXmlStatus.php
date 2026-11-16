<?php

namespace App\Integrations\Anime;

use App\Integrations\Export\ListStatus;

/**
 * The words MyAnimeList's XML export writes in `my_status`: the anime list
 * watches, the manga list reads, and both plan in their own words.
 */
enum MalXmlStatus: string
{
    case Watching = 'Watching';
    case Reading = 'Reading';
    case Completed = 'Completed';
    case OnHold = 'On-Hold';
    case Dropped = 'Dropped';
    case PlanToWatch = 'Plan to Watch';
    case PlanToRead = 'Plan to Read';

    /** The word as a file writes it, in whatever case. */
    public static function read(string $word): ?self
    {
        foreach (self::cases() as $status) {
            if (strcasecmp($status->value, trim($word)) === 0) {
                return $status;
            }
        }

        return null;
    }

    public function listStatus(): ListStatus
    {
        return match ($this) {
            self::Watching, self::Reading => ListStatus::Current,
            self::Completed => ListStatus::Completed,
            self::OnHold => ListStatus::OnHold,
            self::Dropped => ListStatus::Dropped,
            self::PlanToWatch, self::PlanToRead => ListStatus::Planned,
        };
    }
}
