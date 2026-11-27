<?php

namespace App\Tasks\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A sentence worded for its reader, its moments still to be written by the
 * page in the reader's timezone and time format, where every other time on
 * the page is written.
 */
#[TypeScript]
final readonly class WordedLine
{
    /**
     * @param  array<string, string>  $moments  placeholder => ISO 8601 moment
     */
    public function __construct(
        public string $text,
        public array $moments,
    ) {}

    public static function of(TaskLine $line): self
    {
        return new self($line->wording(), $line->momentsOnTheWire());
    }
}
