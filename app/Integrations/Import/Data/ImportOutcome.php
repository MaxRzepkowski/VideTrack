<?php

namespace App\Integrations\Import\Data;

/** How a run ended: the input refused at analysis, or every row read through. */
final readonly class ImportOutcome
{
    /**
     * @param  list<string>  $errors
     * @param  array<array-key, mixed>|null  $readPosition  where a connection's next run starts (a delta timestamp, a cursor)
     */
    private function __construct(
        public bool $finished,
        public string $summary,
        public array $errors = [],
        public ?array $readPosition = null,
    ) {}

    public static function refused(string $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * @param  list<string>  $errors
     * @param  array<array-key, mixed>|null  $readPosition
     */
    public static function finished(string $summary, array $errors, ?array $readPosition): self
    {
        return new self(true, $summary, $errors, $readPosition);
    }
}
