<?php

namespace App\Suggestions\Data;

/**
 * The suggestions sharing one subject (a collection), or all of a source's
 * when it has no subjects.
 */
final readonly class SuggestionGroup
{
    /**
     * @param  list<Suggestion>  $suggestions
     * @param  string|null  $key  the subject within its source, as an address names it
     * @param  string|null  $title  the subject's name
     * @param  string|null  $href  the subject's own page
     * @param  string|null  $caption  where the user stands with the subject ("3 of 5 watched")
     * @param  string|null  $posterPath  the subject's own picture, where it has one
     * @param  int|null  $finished  how much of the subject the user is through, where that is counted
     * @param  int|null  $total  out of how much
     */
    public function __construct(
        public array $suggestions,
        public ?string $key = null,
        public ?string $title = null,
        public ?string $href = null,
        public ?string $caption = null,
        public ?string $posterPath = null,
        public ?int $finished = null,
        public ?int $total = null,
    ) {}
}
