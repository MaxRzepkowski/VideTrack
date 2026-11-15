<?php

namespace App\Tasks\Data;

use App\Enums\TaskRunStatus;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One run on the tasks page: a recurring task or an import, how it ended and
 * what it reported.
 */
#[TypeScript]
final readonly class TaskRunItem
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $details  what the run noted without anything going wrong (a title archived, a collection removed)
     */
    public function __construct(
        public int $id,
        public string $name,
        /** What the task is called, in the reader's language. */
        public string $label,
        public TaskRunStatus $status,
        public ?string $summary,
        public array $errors,
        public array $details,
        public string $queuedAt,
        public ?string $finishedAt,
    ) {}
}
