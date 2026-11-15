<?php

namespace App\Catalog\Data;

use App\Catalog\FilmReleaseType;
use App\Support\Payload;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One dated release of a title in one country. A film opens on different days
 * in different places and in different forms, so "the release date" is a list,
 * not a value — this is one row of it.
 */
#[TypeScript]
final readonly class ReleaseEntry implements JsonSerializable
{
    public function __construct(
        /** ISO 3166-1 country code. */
        public string $region,
        /** Unmerged: the page groups the cinema kinds, its dialog names each. */
        public FilmReleaseType $kind,
        public string $date,
        /** The provider's free-text note ("Netflix", "IMAX re-release"); volunteer-entered, unparsed. */
        public ?string $note = null,
    ) {}

    /**
     * A stored copy, or nothing when it no longer reads as one.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): ?self
    {
        $region = Payload::string($stored, 'region');
        $kind = Payload::enum($stored, 'kind', FilmReleaseType::class);
        $date = Payload::string($stored, 'date');

        return $region === null || $kind === null || $date === null ? null : new self($region, $kind, $date, Payload::string($stored, 'note'));
    }

    /**
     * @return array{
     *     region: string,
     *     kind: value-of<FilmReleaseType>,
     *     date: string,
     *     note: string|null
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'region' => $this->region,
            'kind' => $this->kind->value,
            'date' => $this->date,
            'note' => $this->note,
        ];
    }
}
