<?php

namespace App\Exceptions;

/**
 * A viewing without a date of its own takes the title's release date, and this title has none yet.
 */
final class NoReleaseDate extends LibraryRefusal
{
    public function field(): string
    {
        return 'watched_at';
    }

    public function reason(): string
    {
        return __('media.this_title_has_no_release_date');
    }
}
