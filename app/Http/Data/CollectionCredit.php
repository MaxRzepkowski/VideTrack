<?php

namespace App\Http\Data;

use App\Catalog\Department;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A person across a collection's parts: in how many of them they acted (or
 * directed, or wrote), and under which names or jobs.
 */
#[TypeScript]
final readonly class CollectionCredit implements JsonSerializable
{
    /**
     * @param  list<array{role: string}>  $roles  the characters played or the jobs held, in the shape a title's credit breaks its roles down in, so one cast section reads both
     */
    public function __construct(
        public string $personRef,
        public string $provider,
        public string $name,
        public ?string $profilePath,
        public Department $department,
        public array $roles,
        public int $titleCount,
    ) {}

    /**
     * @return array{
     *     personRef: string,
     *     provider: string,
     *     name: string,
     *     profilePath: string|null,
     *     department: value-of<Department>,
     *     roles: list<array{role: string}>,
     *     titleCount: int
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'personRef' => $this->personRef,
            'provider' => $this->provider,
            'name' => $this->name,
            'profilePath' => $this->profilePath,
            'department' => $this->department->value,
            'roles' => $this->roles,
            'titleCount' => $this->titleCount,
        ];
    }
}
