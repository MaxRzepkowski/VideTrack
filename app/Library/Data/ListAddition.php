<?php

namespace App\Library\Data;

use App\Library\ListAddOutcome;
use App\Models\MediaItem;

/** One title asked onto a list, as asked, with what became of it and its row where it has one. */
final readonly class ListAddition
{
    public function __construct(
        public string $type,
        public string $ref,
        public ListAddOutcome $outcome,
        public ?MediaItem $item = null,
    ) {}
}
