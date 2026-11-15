<?php

namespace App\Catalog\Data;

use App\Catalog\Department;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One entry in a person's filmography: what they did on a given title.
 */
#[TypeScript]
final readonly class PersonCredit implements JsonSerializable
{
    public function __construct(
        public MediaSummary $media,
        public Department $department,
        public ?string $role,
        public float $popularity = 0.0,
        /** The person's place in the cast, the lead first; null for crew and where the provider does not say. */
        public ?int $billing = null,
    ) {}

    /**
     * @return array{
     *     media: MediaSummary,
     *     department: value-of<Department>,
     *     role: string|null,
     *     popularity: float
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'media' => $this->media,
            'department' => $this->department->value,
            'role' => $this->role,
            'popularity' => $this->popularity,
        ];
    }
}
