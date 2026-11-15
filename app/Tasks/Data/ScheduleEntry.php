<?php

namespace App\Tasks\Data;

use App\Enums\TaskRunStatus;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One recurring task in the schedule an admin sees: when it runs and how its
 * last run ended.
 */
#[TypeScript]
final readonly class ScheduleEntry
{
    public function __construct(
        public string $name,
        public string $label,
        /** A translation key: how often it runs. */
        public string $frequency,
        public string $nextRunAt,
        public ?string $lastRunAt,
        public ?TaskRunStatus $lastStatus,
    ) {}
}
