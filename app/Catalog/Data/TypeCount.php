<?php

namespace App\Catalog\Data;

use App\Catalog\MediaTypeRegistry;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How many of something a type holds — a type filter's option with its count.
 */
#[TypeScript]
final readonly class TypeCount implements JsonSerializable
{
    public function __construct(
        public string $type,
        public int $count,
    ) {}

    /**
     * The counted types in the registry's order; a type with nothing counted
     * is left out.
     *
     * @param  array<string, int>  $counts  type code => count
     * @return list<self>
     */
    public static function inOrder(array $counts, MediaTypeRegistry $types): array
    {
        return array_map(
            fn (string $code): self => new self($code, $counts[$code]),
            $types->ordered(array_keys(array_filter($counts))),
        );
    }

    /**
     * @return array{
     *     type: string,
     *     count: int
     * }
     */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, 'count' => $this->count];
    }
}
