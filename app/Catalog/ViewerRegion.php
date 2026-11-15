<?php

namespace App\Catalog;

use App\Models\User;
use App\Support\Locales;

/**
 * The viewer's country: the one place a region is chosen, read by everything
 * regional — where to watch, age certifications, which regional premiere of
 * a game the calendar shows. The user's explicit choice, or the country of
 * their UI language.
 */
final class ViewerRegion
{
    public static function resolve(?User $user, string $locale): string
    {
        return $user?->preferences->region() ?? self::default($locale);
    }

    public static function default(string $locale): string
    {
        return Locales::region($locale);
    }
}
