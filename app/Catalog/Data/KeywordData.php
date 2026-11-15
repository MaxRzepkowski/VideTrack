<?php

namespace App\Catalog\Data;

use App\Support\Payload;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One detail-page keyword; only providers with a browsable keyword catalogue supply a ref. */
#[TypeScript]
final readonly class KeywordData implements JsonSerializable
{
    public function __construct(
        public string $name,
        public ?string $ref = null,
    ) {}

    /**
     * A stored copy, or nothing when it no longer reads as one.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): ?self
    {
        $name = Payload::string($stored, 'name');

        return $name === null ? null : new self($name, Payload::string($stored, 'ref'));
    }

    /**
     * @return array{
     *     name: string,
     *     ref: string|null
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'ref' => $this->ref,
        ];
    }
}
