<?php

namespace App\Policies;

use App\Models\MediaList;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MediaListPolicy
{
    use OwnsResource;

    /**
     * Everything is visible inside the instance by default; a private list
     * stays owner-only, and to anyone else it is not there at all.
     */
    public function view(User $user, MediaList $list): Response
    {
        return $list->is_public ? Response::allow() : $this->owner($user, $list);
    }

    public function update(User $user, MediaList $list): Response
    {
        return $this->owner($user, $list);
    }

    /**
     * The system watchlist cannot be deleted — its owner is told so, anyone
     * else is told nothing.
     */
    public function delete(User $user, MediaList $list): Response
    {
        $owned = $this->owner($user, $list);

        return $owned->denied() || ! $list->isSystem() ? $owned : Response::deny();
    }

    /**
     * Duplicating copies a viewable list into the current user's account.
     */
    public function duplicate(User $user, MediaList $list): Response
    {
        return $this->view($user, $list);
    }
}
