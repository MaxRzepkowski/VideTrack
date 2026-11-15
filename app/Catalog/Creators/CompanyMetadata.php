<?php

namespace App\Catalog\Creators;

use App\Catalog\Data\CompanyDetails;
use App\Support\Payload;

/**
 * What a company's row keeps of its page beyond its own columns, in the
 * `companies.metadata` JSON: written from the page, read back when the page
 * is rebuilt without its provider. Its keys are written and read here only.
 */
final readonly class CompanyMetadata
{
    public function __construct(
        public ?string $description = null,
        public ?string $founded = null,
        public ?string $country = null,
        public ?string $parentName = null,
        public ?string $parentRef = null,
        /** @var array<string, string> label => url */
        public array $externalLinks = [],
    ) {}

    public static function fromDetails(CompanyDetails $details): self
    {
        return new self(
            description: CreatorFields::text($details->description),
            founded: $details->founded,
            country: $details->country,
            parentName: $details->parentName,
            parentRef: $details->parentRef,
            externalLinks: $details->externalLinks,
        );
    }

    /**
     * The stored JSON, whatever an older or damaged row holds: a value of
     * another shape reads as none.
     *
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromStored(?array $stored): self
    {
        $stored ??= [];

        return new self(
            description: Payload::string($stored, 'description'),
            founded: Payload::string($stored, 'founded'),
            country: Payload::string($stored, 'country'),
            parentName: Payload::string($stored, 'parentName'),
            parentRef: Payload::string($stored, 'parentRef'),
            externalLinks: Payload::stringMap($stored['externalLinks'] ?? null),
        );
    }

    /** @return array<string, mixed> the JSON to store, empty fields included (the merge drops them) */
    public function toStored(): array
    {
        return [
            'description' => $this->description,
            'founded' => $this->founded,
            'country' => $this->country,
            'parentName' => $this->parentName,
            'parentRef' => $this->parentRef,
            'externalLinks' => $this->externalLinks,
        ];
    }
}
