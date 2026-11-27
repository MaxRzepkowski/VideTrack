<?php

namespace App\Tasks\Maintenance\Data;

use App\Support\Payload;
use App\Tasks\Data\TaskLine;
use App\Tasks\Maintenance\CheckLevel;
use Carbon\CarbonImmutable;

/**
 * One line of what a check saw: how it reads, the sentence, the sentences
 * under it (what was answered, what to do), what it is about (one provider
 * of several), so the next run can tell what changed, and since when it has
 * stood. A line about one of several named things also says the name and its
 * state in a word, for a row that lists them side by side; the sentence
 * stands alone wherever the line is read away from the others. The one shape
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
        public ?CarbonImmutable $since = null,
        public ?TaskLine $label = null,
        public ?TaskLine $state = null,
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
            Payload::instant($kept['since'] ?? null),
            TaskLine::fromStored($kept['label'] ?? null),
            TaskLine::fromStored($kept['state'] ?? null),
        );
    }

    /**
     * The lines a check's row kept, as they were kept; one that is not a line is left out.
     *
     * @param  list<mixed>|null  $stored
     * @return list<self>
     */
    public static function listFromStored(?array $stored): array
    {
        return array_values(array_filter(array_map(self::fromStored(...), $stored ?? [])));
    }

    /**
     * @return array{level: string, line: array<string, mixed>, details: list<array<string, mixed>>, subject: string|null, since: string|null, label: array<string, mixed>|null, state: array<string, mixed>|null}
     */
    public function stored(): array
    {
        return [
            'level' => $this->level->value,
            'line' => $this->line->stored(),
            'details' => array_map(fn (TaskLine $detail): array => $detail->stored(), $this->details),
            'subject' => $this->subject,
            'since' => $this->since?->toIso8601String(),
            'label' => $this->label?->stored(),
            'state' => $this->state?->stored(),
        ];
    }
}
