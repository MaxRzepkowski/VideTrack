<?php

namespace App\Catalog\Data;

use App\Support\Payload;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The collection a title belongs to (a saga, a series), as its details name
 * it: enough for the banner on the title's page and to open the collection.
 */
#[TypeScript]
final readonly class CollectionLink implements JsonSerializable
{
    public function __construct(
        public string $ref,
        public string $name,
        public ?string $posterPath = null,
        public ?string $backdropPath = null,
    ) {}

    /**
     * A stored copy, or nothing when it no longer reads as one.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): ?self
    {
        $ref = Payload::string($stored, 'ref');
        $name = Payload::string($stored, 'name');

        return $ref === null || $name === null
            ? null
            : new self($ref, $name, Payload::string($stored, 'posterPath'), Payload::string($stored, 'backdropPath'));
    }

    /**
     * @return array{
     *     ref: string,
     *     name: string,
     *     posterPath: string|null,
     *     backdropPath: string|null
     * }
     */
    public function jsonSerialize(): array
    {
        return ['ref' => $this->ref, 'name' => $this->name, 'posterPath' => $this->posterPath, 'backdropPath' => $this->backdropPath];
    }
}
