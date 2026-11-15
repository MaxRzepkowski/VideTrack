<?php

namespace App\Suggestions;

/**
 * How a title that was still an announcement came to the user's attention:
 * the ways in of the "out since you added them" shelf.
 */
enum AddedVia: string
{
    /** A place on one of the user's lists. */
    case Listed = 'listed';

    /** A bell: the user's own, or one a creator they follow rang for the title. */
    case Followed = 'followed';

    public function label(): string
    {
        return match ($this) {
            self::Listed => __('suggestions.on_a_list'),
            self::Followed => __('suggestions.followed'),
        };
    }
}
