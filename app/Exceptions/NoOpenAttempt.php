<?php

namespace App\Exceptions;

/**
 * Only an unfinished attempt can be dropped.
 */
final class NoOpenAttempt extends LibraryRefusal
{
    public function field(): string
    {
        return 'status';
    }

    public function reason(): string
    {
        return __('media.you_have_completed_this_title_only');
    }
}
