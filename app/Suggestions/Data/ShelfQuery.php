<?php

namespace App\Suggestions\Data;

use App\Enums\SortDirection;
use App\Suggestions\ShelfSort;

/**
 * A suggestion shelf's controls as its address carries them. Each is only a
 * wish: the page keeps what the shelf can answer.
 */
final readonly class ShelfQuery
{
    public function __construct(
        public ShelfSort $sort = ShelfSort::Suggested,
        public SortDirection $dir = SortDirection::Asc,
        public ?string $type = null,
        public ?string $origin = null,
        /** The first year of a decade of release. */
        public ?int $decade = null,
        public ?string $q = null,
        public int $page = 1,
    ) {}

    /** Whether a title passes every filter set. */
    public function admits(Suggestion $suggestion): bool
    {
        $media = $suggestion->media;

        return ($this->type === null || $media->type === $this->type)
            && ($this->origin === null || in_array($this->origin, $suggestion->origins, true))
            && ($this->decade === null || ($media->year !== null && intdiv($media->year, 10) * 10 === $this->decade))
            && ($this->q === null || mb_stripos($media->title, $this->q) !== false);
    }

    /** The same wishes with the origin and the order the shelf can actually answer. */
    public function settled(?string $origin, ShelfSort $sort): self
    {
        return new self($sort, $this->dir, $this->type, $origin, $this->decade, $this->q, $this->page);
    }

    public function withoutSearch(): self
    {
        return new self($this->sort, $this->dir, $this->type, $this->origin, $this->decade, null, $this->page);
    }
}
