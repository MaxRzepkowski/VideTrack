<?php

namespace App\Catalog\Data;

use App\Support\Payload;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The collections a collection is tied to, as its provider names them: the
 * series it belongs to, the subseries and spin-off series under it.
 */
#[TypeScript]
final readonly class CollectionRelations implements JsonSerializable
{
    /**
     * @param  list<CollectionLink>  $subseries
     * @param  list<CollectionLink>  $spinOffSeries
     */
    public function __construct(
        public ?CollectionLink $parent = null,
        public array $subseries = [],
        public array $spinOffSeries = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->parent === null && $this->subseries === [] && $this->spinOffSeries === [];
    }

    /**
     * As a list keeps it: each link in its own stored shape.
     *
     * @return array{parent: array<string, mixed>|null, subseries: list<array<string, mixed>>, spinOffSeries: list<array<string, mixed>>}
     */
    public function toStored(): array
    {
        return [
            'parent' => $this->parent?->jsonSerialize(),
            'subseries' => array_map(fn (CollectionLink $link): array => $link->jsonSerialize(), $this->subseries),
            'spinOffSeries' => array_map(fn (CollectionLink $link): array => $link->jsonSerialize(), $this->spinOffSeries),
        ];
    }

    /**
     * Read back from a list; what no longer reads as a link drops out.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        $links = fn (mixed $value): array => Payload::listOf($value, CollectionLink::fromStored(...));

        return new self(
            parent: CollectionLink::fromStored(Payload::map($stored['parent'] ?? null)),
            subseries: $links($stored['subseries'] ?? null),
            spinOffSeries: $links($stored['spinOffSeries'] ?? null),
        );
    }

    /**
     * @return array{
     *     parent: CollectionLink|null,
     *     subseries: list<CollectionLink>,
     *     spinOffSeries: list<CollectionLink>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'parent' => $this->parent,
            'subseries' => $this->subseries,
            'spinOffSeries' => $this->spinOffSeries,
        ];
    }
}
