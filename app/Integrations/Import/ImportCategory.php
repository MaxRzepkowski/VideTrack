<?php

namespace App\Integrations\Import;

use App\Integrations\Import\Data\ManifestCategory;

/**
 * The kinds of thing a tracker's export holds, as the selection offers
 * them. A source whose own vocabulary decides what a row is (Goodreads'
 * shelves, StoryGraph's statuses) keeps that one instead.
 */
enum ImportCategory: string
{
    case History = 'history';
    case Ratings = 'ratings';
    case Watchlist = 'watchlist';
    case Favorites = 'favorites';

    /** The category as the selection lists it, with how many entries the input holds. */
    public function counted(int $count): ManifestCategory
    {
        return new ManifestCategory($this->value, __($this->labelKey()), $count);
    }

    private function labelKey(): string
    {
        return match ($this) {
            self::History => 'import.watch_history',
            self::Ratings => 'common.ratings',
            self::Watchlist => 'import.watchlist',
            self::Favorites => 'common.favorites',
        };
    }
}
