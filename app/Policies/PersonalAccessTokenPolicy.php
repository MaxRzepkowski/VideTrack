<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * An API token belongs to whoever it was issued to. Somebody else's is not
 * theirs to revoke — and not theirs to learn the existence of either.
 */
final class PersonalAccessTokenPolicy
{
    public function delete(User $user, PersonalAccessToken $token): Response
    {
        return $token->tokenable_type === $user->getMorphClass() && $token->tokenable_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
