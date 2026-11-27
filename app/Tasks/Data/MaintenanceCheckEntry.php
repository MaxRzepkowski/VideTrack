<?php

namespace App\Tasks\Data;

use App\Enums\TaskRunStatus;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One maintenance check as the admin sees it: how often it runs, where it
 * stands and what its last run saw.
 */
#[TypeScript]
final readonly class MaintenanceCheckEntry
{
    /**
     * @param  list<MaintenanceCheckLine>  $lines  what its last run saw
     */
    public function __construct(
        public string $code,
        public string $label,
        /** A translation key: how often it runs. */
        public string $frequency,
        public ?TaskRunStatus $status,
        public array $lines,
        public ?string $error,
        public ?string $startedAt,
        public ?string $checkedAt,
    ) {}
}
