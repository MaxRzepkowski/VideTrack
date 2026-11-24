<?php

namespace App\Policies;

use App\Models\MediaList;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MediaListPolicy
{
    use OwnsResource;

    public function __construct(private UserPolicy $profiles) {}

    /**
     * Everything is visible inside the instance by default; a private list
     * stays owner-only, and to anyone else it is not there at all. A private
     * profile keeps its lists with it, public or not: wherever the list is
     * asked for, its owner's profile has to be visible too.
     */
    public function view(User $user, MediaList $list): Response
    {
        if (! $list->is_public) {
            return $this->owner($user, $list);
        }

        return $list->user === null ? Response::allow() : $this->profiles->view($user, $list->user);
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
