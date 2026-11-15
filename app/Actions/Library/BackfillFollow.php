<?php

namespace App\Actions\Library;

use App\Enums\FollowMode;
use App\Library\Sponsorships;
use App\Models\MediaItem;
use App\Models\User;

/**
 * The bell on a title from the user's history elsewhere (a restored backup).
 * It is written as {@see SetFollow} writes it, but never over a decision the
 * user made here: their own bell or mute stands. An auto-follow a followed
 * creator rang is no decision, so the file's bell takes it over.
 */
final readonly class BackfillFollow
{
    public function __construct(
        private SetFollow $follow,
        private Sponsorships $sponsorships,
    ) {}

    /**
     * @return bool whether the bell was written
     */
    public function execute(User $user, MediaItem $root, FollowMode $mode): bool
    {
        $row = $user->follows()->where('media_item_id', $root->id)->first();

        if ($row !== null && ($row->mode === FollowMode::Mute || ! $this->sponsorships->isSponsored($row))) {
            return false;
        }

        $this->follow->execute($user, $root, $mode);

        return true;
    }
}
