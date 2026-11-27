<?php

namespace App\Tasks\Data;

use App\Tasks\Maintenance\CheckLevel;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One line of a check's row, worded for the reader, with the sentences under it. */
#[TypeScript]
final readonly class MaintenanceCheckLine
{
    /**
     * @param  list<WordedLine>  $details
     */
    public function __construct(
        public CheckLevel $level,
        public WordedLine $line,
        public array $details,
    ) {}
}
