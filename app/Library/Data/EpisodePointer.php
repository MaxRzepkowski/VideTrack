<?php

namespace App\Library\Data;

use App\Catalog\Data\CascadeLeaf;
use App\Models\MediaItem;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The episode a container's progress points at: the first one still to watch. */
#[TypeScript]
final readonly class EpisodePointer implements JsonSerializable
{
    public function __construct(
        public string $type,
        public string $ref,
        public string $title,
        public ?int $number,
        /** Null when the container is the season itself. */
        public ?int $seasonNumber,
    ) {}

    public static function to(CascadeLeaf $leaf): self
    {
        $path = $leaf->path;

        return new self(
            type: $leaf->type,
            ref: $leaf->ref,
            title: $leaf->title,
            number: $leaf->number,
            seasonNumber: count($path) >= 2 ? $path[count($path) - 2] : null,
        );
    }

    /**
     * The same pointer read back from a stored id: the episode with its
     * parent loaded, under the container it is the next one of.
     */
    public static function at(MediaItem $leaf, int $containerId): self
    {
        $parent = $leaf->parent;

        return new self(
            type: $leaf->type,
            ref: $leaf->provider_ref,
            title: $leaf->title,
            number: $leaf->number,
            seasonNumber: $parent === null || $parent->id === $containerId ? null : (int) ($parent->number ?? 0),
        );
    }

    /**
     * @return array{
     *     type: string,
     *     ref: string,
     *     title: string,
     *     number: int|null,
     *     seasonNumber: int|null
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'ref' => $this->ref,
            'title' => $this->title,
            'number' => $this->number,
            'seasonNumber' => $this->seasonNumber,
        ];
    }
}
