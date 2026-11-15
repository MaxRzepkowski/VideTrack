<?php

namespace App\Catalog\Data;

use App\Models\MediaItem;
use App\Support\QueryRow;
use stdClass;

/**
 * A playable leaf a cascade from a container reaches (an episode of a show
 * or a season), read as a plain row: a long-running show has tens of
 * thousands of them, too many to hydrate as models.
 */
final readonly class CascadeLeaf
{
    /**
     * @param  list<int>  $path  the numbers from the container down to the leaf ([season, episode] under a show)
     */
    public function __construct(
        public int $id,
        public ?int $parentId,
        public string $type,
        public string $ref,
        public string $title,
        public ?int $number,
        public ?int $runtime,
        public bool $aired,
        public array $path,
    ) {}

    /**
     * A container the descent starts from.
     *
     * @param  list<int>  $path
     */
    public static function fromItem(MediaItem $item, bool $aired, array $path): self
    {
        return new self(
            id: $item->id,
            parentId: $item->parent_id,
            type: $item->type,
            ref: $item->provider_ref,
            title: $item->title,
            number: $item->number,
            runtime: $item->runtime,
            aired: $aired,
            path: $path,
        );
    }

    /**
     * A child row as the descent selects it.
     *
     * @param  list<int>  $path
     */
    public static function fromRow(stdClass $row, bool $aired, array $path): self
    {
        return new self(
            id: QueryRow::int($row, 'id'),
            parentId: QueryRow::intOrNull($row, 'parent_id'),
            type: QueryRow::text($row, 'type'),
            ref: QueryRow::text($row, 'provider_ref'),
            title: QueryRow::text($row, 'title'),
            number: QueryRow::intOrNull($row, 'number'),
            runtime: QueryRow::intOrNull($row, 'runtime'),
            aired: $aired,
            path: $path,
        );
    }

    /** Hierarchy order: number by number from the top, a shorter path before the ones it starts. */
    public static function compare(self $a, self $b): int
    {
        foreach ($a->path as $level => $number) {
            if (! isset($b->path[$level])) {
                return 1;
            }

            if ($number !== $b->path[$level]) {
                return $number <=> $b->path[$level];
            }
        }

        return count($a->path) <=> count($b->path);
    }
}
