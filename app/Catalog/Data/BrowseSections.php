<?php

namespace App\Catalog\Data;

/**
 * The browse sections of one type at its provider: trending, popular and
 * upcoming titles, each a short row.
 */
final readonly class BrowseSections
{
    /**
     * @param  list<MediaSummary>  $trending
     * @param  list<MediaSummary>  $popular
     * @param  list<MediaSummary>  $upcoming
     */
    public function __construct(
        public array $trending,
        public array $popular,
        public array $upcoming,
    ) {}

    /**
     * Every card of the three sections, for the user's states.
     *
     * @return list<MediaSummary>
     */
    public function cards(): array
    {
        return [...$this->trending, ...$this->popular, ...$this->upcoming];
    }
}
