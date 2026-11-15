<?php

namespace App\Catalog\Data;

use App\Catalog\Department;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class PersonDetails implements JsonSerializable
{
    public function __construct(
        public string $ref,
        public string $name,
        public ?string $profilePath = null,
        public ?string $biography = null,
        public ?Department $knownForDepartment = null,
        public ?string $birthday = null,
        public ?string $deathday = null,
        public ?string $placeOfBirth = null,
        /** Where they come from, as loosely as the provider says it (a country, a town they live in); not their birthplace. */
        public ?string $origin = null,
        /** @var list<string> */
        public array $alsoKnownAs = [],
        /** @var list<string> Additional portraits, highest-rated first. */
        public array $photos = [],
        /** @var array<string, string> label => url */
        public array $externalLinks = [],
        /** @var list<PersonCredit> */
        public array $credits = [],
    ) {}

    /**
     * @return array{
     *     ref: string,
     *     name: string,
     *     profilePath: string|null,
     *     biography: string|null,
     *     knownForDepartment: value-of<Department>|null,
     *     birthday: string|null,
     *     deathday: string|null,
     *     placeOfBirth: string|null,
     *     origin: string|null,
     *     alsoKnownAs: list<string>,
     *     photos: list<string>,
     *     externalLinks: array<string, string>,
     *     credits: list<PersonCredit>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'ref' => $this->ref,
            'name' => $this->name,
            'profilePath' => $this->profilePath,
            'biography' => $this->biography,
            'knownForDepartment' => $this->knownForDepartment?->value,
            'birthday' => $this->birthday,
            'deathday' => $this->deathday,
            'placeOfBirth' => $this->placeOfBirth,
            'origin' => $this->origin,
            'alsoKnownAs' => $this->alsoKnownAs,
            'photos' => $this->photos,
            'externalLinks' => $this->externalLinks,
            'credits' => $this->credits,
        ];
    }
}
