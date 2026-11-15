<?php

namespace App\Integrations\Import\Rows;

use App\Enums\FollowMode;
use App\Integrations\Import\Data\ImportCycle;
use App\Integrations\Import\Data\ImportDrop;
use App\Integrations\Import\Data\ImportListEntry;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportStatus;
use App\Integrations\Import\Data\MediaSubject;

/**
 * Everything the input says about one title. The runner resolves the
 * subject once, then applies what is present in a fixed order: the runs
 * before the plays in them, the plays before the drop, because a drop older
 * than a finished play no longer holds.
 *
 * A row with nothing but a subject still resolves it — that is how an entry
 * this instance has no provider for ends up in the report.
 */
final readonly class MediaRow implements ImportRow
{
    /**
     * @param  list<ImportPlay>  $plays
     * @param  list<ImportListEntry>  $lists
     * @param  list<ImportCycle>  $cycles
     */
    public function __construct(
        public MediaSubject $subject,
        public array $plays = [],
        public ?ImportRating $rating = null,
        public ?ImportStatus $status = null,
        public ?ImportDrop $drop = null,
        public ?ImportMark $watchlist = null,
        public ?ImportMark $favorite = null,
        public array $lists = [],
        public array $cycles = [],
        public ?FollowMode $follow = null,
    ) {}
}
