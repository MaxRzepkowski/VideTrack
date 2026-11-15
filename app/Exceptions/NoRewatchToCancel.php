<?php

namespace App\Exceptions;

/**
 * Only an explicit rewatch can be undone; the first viewing has no cycle row.
 */
final class NoRewatchToCancel extends LibraryRefusal
{
    public function field(): string
    {
        return 'cycle';
    }

    public function reason(): string
    {
        return __('media.there_is_no_rewatch_to_undo');
    }
}
