<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody else's resource answers 404, not 403: a 403 would confirm that the
 * id exists and belongs to another account.
 */
trait OwnsResource
{
    private function owner(User $user, Model $resource): Response
    {
        return $resource->getAttribute('user_id') === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
