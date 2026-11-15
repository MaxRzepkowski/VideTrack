<?php

namespace App\Catalog\Data;

use App\Support\Payload;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One country's age classification of a title. Which of them a page shows on
 * its chip is the viewer's region's business; the whole list travels so the
 * classification dialog can name every country that filed one.
 */
#[TypeScript]
final readonly class CertificationData implements JsonSerializable
{
    public function __construct(
        /** ISO 3166-1 country code. */
        public string $region,
        /** The symbol itself (PG-13, 16, TV-MA…). */
        public string $code,
        /** What the symbol means; null when the provider's dictionary is unavailable. */
        public ?string $meaning = null,
    ) {}

    /**
     * A stored copy, or nothing when it no longer reads as one.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): ?self
    {
        $region = Payload::string($stored, 'region');
        $code = Payload::string($stored, 'code');

        return $region === null || $code === null ? null : new self($region, $code, Payload::string($stored, 'meaning'));
    }

    /**
     * @return array{
     *     region: string,
     *     code: string,
     *     meaning: string|null
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'region' => $this->region,
            'code' => $this->code,
            'meaning' => $this->meaning,
        ];
    }
}
