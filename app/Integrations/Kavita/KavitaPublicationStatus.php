<?php

namespace App\Integrations\Kavita;

/**
 * Where a series stands in its publication (`SeriesMetadataDto.publicationStatus`):
 * read to its last volume, an ongoing series is caught up with, not finished.
 */
enum KavitaPublicationStatus: int
{
    case Ongoing = 0;
    case Hiatus = 1;
    case Completed = 2;
    case Cancelled = 3;
    case Ended = 4;

    /**
     * The statuses after which no more of a series is coming.
     *
     * @return list<self>
     */
    public static function over(): array
    {
        return [self::Completed, self::Cancelled, self::Ended];
    }

    /** The same statuses as Kavita's filter takes them, a comma-separated list. */
    public static function overList(): string
    {
        return implode(',', array_map(fn (self $status): int => $status->value, self::over()));
    }
}
