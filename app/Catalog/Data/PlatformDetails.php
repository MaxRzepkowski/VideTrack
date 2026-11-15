<?php

namespace App\Catalog\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A platform, what kind of machine it is, and its titles: those coming and the best rated. */
#[TypeScript]
final readonly class PlatformDetails implements JsonSerializable
{
    /**
     * @param  list<MediaSummary>  $upcoming  soonest first
     * @param  list<MediaSummary>  $topRated  best rated first
     */
    public function __construct(
        public string $ref,
        public string $name,
        public ?string $abbreviation = null,
        public ?string $logoPath = null,
        /** The provider's word for the machine (Console, Portable console, Computer…). */
        public ?string $kind = null,
        /** The family it belongs to (PlayStation, Xbox, Nintendo…). */
        public ?string $family = null,
        public ?int $generation = null,
        public ?string $summary = null,
        public array $upcoming = [],
        public array $topRated = [],
    ) {}

    /** @return list<MediaSummary> every title shown, for the cards' states */
    public function titles(): array
    {
        return [...$this->upcoming, ...$this->topRated];
    }

    /**
     * @return array{
     *     ref: string,
     *     name: string,
     *     abbreviation: string|null,
     *     logoPath: string|null,
     *     kind: string|null,
     *     family: string|null,
     *     generation: int|null,
     *     summary: string|null,
     *     upcoming: list<MediaSummary>,
     *     topRated: list<MediaSummary>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'ref' => $this->ref,
            'name' => $this->name,
            'abbreviation' => $this->abbreviation,
            'logoPath' => $this->logoPath,
            'kind' => $this->kind,
            'family' => $this->family,
            'generation' => $this->generation,
            'summary' => $this->summary,
            'upcoming' => $this->upcoming,
            'topRated' => $this->topRated,
        ];
    }
}
