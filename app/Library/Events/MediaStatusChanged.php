<?php

namespace App\Library\Events;

use App\Enums\MediaUserStatus;
use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The open attempt's status moved. Both sides are nullable: null as $status
 * means the attempt was resumed (or discarded), null as $previous means there
 * was no open attempt before.
 */
final readonly class MediaStatusChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $userId,
        public MutationSource $source,
        public int $itemId,
        public ?MediaUserStatus $status,
        public ?MediaUserStatus $previous,
    ) {}
}
