<?php

namespace App\Tasks\Maintenance;

/** What the orphan sweep looks after, in the order it removes them. */
enum OrphanKind: string
{
    case Titles = 'titles';
    case People = 'people';
    case Companies = 'companies';
    case Collections = 'collections';

    /** A translation key: what the sweep's row calls the kind. */
    public function label(): string
    {
        return match ($this) {
            self::Titles => 'tasks.orphan_titles',
            self::People => 'tasks.orphan_people',
            self::Companies => 'tasks.orphan_companies',
            self::Collections => 'tasks.orphan_collections',
        };
    }
}
