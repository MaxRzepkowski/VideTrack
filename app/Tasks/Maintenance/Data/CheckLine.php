<?php

namespace App\Tasks\Maintenance\Data;

use App\Support\Payload;
use App\Tasks\Data\TaskLine;
use App\Tasks\Maintenance\CheckLevel;

/**
 * One line of what a check saw: how it reads, the sentence, the sentences
 * under it (what was answered, what to do), and what it is about (one
 * provider of several), so the next run can tell what changed. The one shape
 * a check's row keeps.
 */
final readonly class CheckLine
{
    /**
     * @param  list<TaskLine>  $details
     */
    public function __construct(
        public CheckLevel $level,
        public TaskLine $line,
        public array $details = [],
        public ?string $subject = null,
    ) {}

    /** The line as it was kept; one that is not a line reads as nothing. */
    public static function fromStored(mixed $stored): ?self
    {
        $kept = Payload::map($stored);
        $level = Payload::enum($kept, 'level', CheckLevel::class);
        $line = TaskLine::fromStored($kept['line'] ?? null);

        if ($level === null || $line === null) {
            return null;
        }

        return new self(
            $level,
            $line,
            array_values(array_filter(array_map(TaskLine::fromStored(...), Payload::list($kept['details'] ?? null)))),
            Payload::string($kept, 'subject'),
        );
    }

    /**
     * @return array{level: string, line: array<string, mixed>, details: list<array<string, mixed>>, subject: string|null}
     */
    public function stored(): array
    {
        return [
            'level' => $this->level->value,
            'line' => $this->line->stored(),
            'details' => array_map(fn (TaskLine $detail): array => $detail->stored(), $this->details),
            'subject' => $this->subject,
        ];
    }
}
