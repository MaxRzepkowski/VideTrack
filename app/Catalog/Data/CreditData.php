<?php

namespace App\Catalog\Data;

use App\Catalog\Department;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class CreditData implements JsonSerializable
{
    public function __construct(
        public string $personRef,
        /** Provider owning the person page this credit links to. */
        public string $provider,
        public string $name,
        public ?string $profilePath,
        public Department $department,
        /** Character (cast) or job title (crew). */
        public ?string $role,
        public int $order = 0,
        /** Total episodes the person appears in / works on (aggregate credits); null elsewhere. */
        public ?int $episodeCount = null,
        /**
         * Per-character / per-job breakdown (aggregate credits) — a person can
         * play several characters across seasons. Empty for movies / episodes.
         *
         * @var list<array{role: string|null, episodeCount: int|null}>
         */
        public array $roles = [],
    ) {}

    /**
     * @return array{
     *     personRef: string,
     *     name: string,
     *     profilePath: string|null,
     *     department: value-of<Department>,
     *     role: string|null,
     *     order: int,
     *     episodeCount: int|null,
     *     roles: list<array{role: string|null, episodeCount: int|null}>,
     *     provider: string
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'personRef' => $this->personRef,
            'name' => $this->name,
            'profilePath' => $this->profilePath,
            'department' => $this->department->value,
            'role' => $this->role,
            'order' => $this->order,
            'episodeCount' => $this->episodeCount,
            'roles' => $this->roles,
            'provider' => $this->provider,
        ];
    }
}
