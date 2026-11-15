<?php

namespace App\Catalog\Creators;

use App\Catalog\Data\PersonDetails;
use App\Catalog\Department;
use App\Support\Payload;

/**
 * What a person's row keeps of their page beyond its own columns, in the
 * `people.metadata` JSON: written from the page, read back when the page is
 * rebuilt without its provider. Its keys are written and read here only.
 */
final readonly class PersonMetadata
{
    public function __construct(
        public ?Department $knownForDepartment = null,
        public ?string $placeOfBirth = null,
        public ?string $origin = null,
        /** @var list<string> */
        public array $alsoKnownAs = [],
        /** @var array<string, string> link kind => url */
        public array $externalLinks = [],
    ) {}

    public static function fromDetails(PersonDetails $details): self
    {
        return new self(
            knownForDepartment: $details->knownForDepartment,
            placeOfBirth: $details->placeOfBirth,
            origin: $details->origin,
            alsoKnownAs: $details->alsoKnownAs,
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
            knownForDepartment: Payload::enum($stored, 'knownForDepartment', Department::class),
            placeOfBirth: Payload::string($stored, 'placeOfBirth'),
            origin: Payload::string($stored, 'origin'),
            alsoKnownAs: Payload::strings($stored['alsoKnownAs'] ?? null),
            externalLinks: Payload::stringMap($stored['externalLinks'] ?? null),
        );
    }

    /** @return array<string, mixed> the JSON to store, empty fields included (the merge drops them) */
    public function toStored(): array
    {
        return [
            'knownForDepartment' => $this->knownForDepartment?->value,
            'placeOfBirth' => $this->placeOfBirth,
            'origin' => $this->origin,
            'alsoKnownAs' => $this->alsoKnownAs,
            'externalLinks' => $this->externalLinks,
        ];
    }
}
