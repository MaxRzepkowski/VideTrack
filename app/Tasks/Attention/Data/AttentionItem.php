<?php

namespace App\Tasks\Attention\Data;

use App\Tasks\Data\WordedLine;
use App\Tasks\Maintenance\CheckLevel;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Something about the instance worded for its admin: what is wrong, what was
 * seen and what to do, and since when. A problem stops something working; a
 * warning may be harmless where the admin knows why.
 */
#[TypeScript]
final readonly class AttentionItem
{
    /**
     * @param  list<WordedLine>  $details
     */
    public function __construct(
        public CheckLevel $level,
        public string $title,
        public array $details,
        public ?string $since,
    ) {}
}
