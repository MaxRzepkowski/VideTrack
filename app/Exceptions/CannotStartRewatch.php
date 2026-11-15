<?php

namespace App\Exceptions;

/**
 * A new viewing cycle starts on a container root (a show) with something watched in the current cycle.
 */
final class CannotStartRewatch extends LibraryRefusal
{
    public function field(): string
    {
        return 'cycle';
    }

    public function reason(): string
    {
        return __('media.this_title_cannot_start_a_new');
    }
}
