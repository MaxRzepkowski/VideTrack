<?php

namespace App\Tracking\Data;

use App\Tracking\BellEntryKind;

/**
 * The bell list's controls as its address carries them. Both are only wishes:
 * the page keeps one when something of that kind or type is on the list.
 */
final readonly class BellListFilters
{
    public function __construct(
        public ?BellEntryKind $kind = null,
        public ?string $type = null,
    ) {}
}
