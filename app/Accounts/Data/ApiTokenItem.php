<?php

namespace App\Accounts\Data;

use App\Support\Payload;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the user's API tokens on the settings page: never its secret, which
 * the database keeps only as a hash.
 */
#[TypeScript]
final readonly class ApiTokenItem
{
    /**
     * @param  list<string>  $abilities
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $abilities,
        public ?string $lastUsedAt,
        public ?string $createdAt,
        public ?string $expiresAt,
    ) {}

    public static function of(PersonalAccessToken $token): self
    {
        return new self(
            id: $token->id,
            name: $token->name,
            abilities: Payload::strings($token->abilities),
            lastUsedAt: $token->last_used_at?->toIso8601String(),
            createdAt: $token->created_at?->toIso8601String(),
            expiresAt: $token->expires_at?->toIso8601String(),
        );
    }
}
