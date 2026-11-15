<?php

namespace App\Actions\Library;

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorRef;
use App\Catalog\Creators\Creators;
use App\Enums\FollowMode;
use App\Models\Follow;
use App\Models\User;

/**
 * The bell on a creator from the user's history elsewhere (a restored
 * backup), written as {@see SetCreatorFollow} writes it unless the user has
 * rung or silenced that creator here already. A person or a company gets its
 * row from the name in the file — an import never asks a provider per entry;
 * a collection is read from its provider, which holds its parts.
 */
final readonly class BackfillCreatorFollow
{
    public function __construct(
        private SetCreatorFollow $follow,
        private Creators $creators,
    ) {}

    /**
     * @return bool whether the bell was written
     */
    public function execute(User $user, CreatorRef $creator, string $name, FollowMode $mode, string $locale): bool
    {
        $row = $this->creators->find($creator);

        if ($row === null && $creator->kind !== CreatorKind::Collection) {
            $row = $this->creators->firstOrCreateNamed($creator, $name);
        }

        if ($row !== null && $user->follows()->where(Follow::creatorColumn($creator->kind), $row->id)->exists()) {
            return false;
        }

        $this->follow->execute($user, $creator, $mode, $locale);

        return true;
    }
}
