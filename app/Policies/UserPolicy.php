<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Profiles are visible inside the instance by default; a private profile
     * (activity and lists included) stays visible only to its owner and
     * admins, and to anyone else it is not there at all.
     */
    public function view(User $viewer, User $profile): Response
    {
        return ! $profile->is_private || $viewer->id === $profile->id || $viewer->isAdmin()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * The bell list is nobody else's business — not even an admin's. Another
     * user's answers 404: it should not even be known to exist.
     */
    public function viewFollowing(User $viewer, User $profile): Response
    {
        return $viewer->id === $profile->id ? Response::allow() : Response::denyAsNotFound();
    }
}
