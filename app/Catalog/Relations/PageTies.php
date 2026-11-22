<?php

namespace App\Catalog\Relations;

use App\Catalog\Data\MediaSummary;

/**
 * A title's ties as its page lays them out: the titles of its universe in
 * one section, its works in other media in another.
 */
final readonly class PageTies
{
    /**
     * @param  list<TitleTie>  $universe
     * @param  list<TitleTie>  $otherMedia
     */
    public function __construct(
        public array $universe = [],
        public array $otherMedia = [],
    ) {}

    /**
     * @param  list<TitleTie>  $ties  in page order
     */
    public static function of(array $ties): self
    {
        return new self(
            array_values(array_filter($ties, fn (TitleTie $tie): bool => ! $tie->relation->acrossMedia())),
            array_values(array_filter($ties, fn (TitleTie $tie): bool => $tie->relation->acrossMedia())),
        );
    }

    /**
     * Every card of both sections, for the viewer's states.
     *
     * @return list<MediaSummary>
     */
    public function cards(): array
    {
        return array_map(fn (TitleTie $tie) => $tie->media, [...$this->universe, ...$this->otherMedia]);
    }
}
