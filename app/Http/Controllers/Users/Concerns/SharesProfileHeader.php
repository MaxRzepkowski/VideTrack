<?php

namespace App\Http\Controllers\Users\Concerns;

use App\Models\User;
use App\Users\Data\ProfileData;

/**
 * Every page of a user (profile, history, ratings, favorites, in progress,
 * lists, following) is one view with one header: the same cover, avatar and
 * tab bar on top, only the panel below it changes. They all send the header
 * the same props from here, so the block never drifts between tabs.
 */
trait SharesProfileHeader
{
    /**
     * @return array{profile: ProfileData, isOwner: bool}
     */
    protected function profileHeader(User $viewer, User $user): array
    {
        return [
            'profile' => ProfileData::of($user),
            'isOwner' => $viewer->id === $user->id,
        ];
    }
}
