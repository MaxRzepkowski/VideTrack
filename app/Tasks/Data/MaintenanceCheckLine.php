<?php

namespace App\Tasks\Data;

use App\Tasks\Maintenance\CheckLevel;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of a check's row, worded for the reader, with the sentences under
 * it; a line about one of several named things also has the name and its
 * state in a word, to stand in a row beside the others.
 */
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
        public ?string $label,
        public ?WordedLine $state,
    ) {}
}
