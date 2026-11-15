<?php

namespace App\Catalog\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A company page: a game studio or publisher, a film studio, a TV network.
 * The company analogue of PersonDetails: a logo instead of a portrait, a
 * founding date instead of a birthday, and the titles it made (developed,
 * published, produced, aired) instead of a filmography.
 */
#[TypeScript]
final readonly class CompanyDetails implements JsonSerializable
{
    public function __construct(
        public string $ref,
        public string $name,
        public ?string $logoPath = null,
        public ?string $description = null,
        public ?string $founded = null,
        /** ISO 3166-1 alpha-2; the page names it in the viewer's language. */
        public ?string $country = null,
        /** Parent company (the studio is a subsidiary), linkable to its own page. */
        public ?string $parentName = null,
        public ?string $parentRef = null,
        /** @var array<string, string> label => url */
        public array $externalLinks = [],
        /** @var list<CompanyCredit> */
        public array $credits = [],
        /** The provider holds more titles than this first chunk carries. */
        public bool $hasMoreCredits = false,
    ) {}

    /**
     * @return array{
     *     ref: string,
     *     name: string,
     *     logoPath: string|null,
     *     description: string|null,
     *     founded: string|null,
     *     country: string|null,
     *     parentName: string|null,
     *     parentRef: string|null,
     *     externalLinks: array<string, string>,
     *     credits: list<CompanyCredit>,
     *     hasMoreCredits: bool
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'ref' => $this->ref,
            'name' => $this->name,
            'logoPath' => $this->logoPath,
            'description' => $this->description,
            'founded' => $this->founded,
            'country' => $this->country,
            'parentName' => $this->parentName,
            'parentRef' => $this->parentRef,
            'externalLinks' => $this->externalLinks,
            'credits' => $this->credits,
            'hasMoreCredits' => $this->hasMoreCredits,
        ];
    }
}
