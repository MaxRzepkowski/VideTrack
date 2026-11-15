<?php

namespace App\Users\Data;

use App\Models\User;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The header every page of a user carries: who they are, as the profile
 * shows them to anyone allowed to see it.
 */
#[TypeScript]
final readonly class ProfileData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $username,
        public ?string $bio,
        public ?string $avatar,
        public bool $isPrivate,
        /** A calendar date (YYYY-MM-DD). */
        public ?string $memberSince,
    ) {}

    public static function of(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            username: $user->username,
            bio: $user->bio,
            avatar: $user->avatar,
            isPrivate: $user->is_private,
            memberSince: $user->created_at?->format('Y-m-d'),
        );
    }
}
