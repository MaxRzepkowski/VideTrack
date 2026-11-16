<?php

namespace App\Policies;

use App\Models\Identity;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class IdentityPolicy
{
    use OwnsResource;

    public function delete(User $user, Identity $identity): Response
    {
        return $this->owner($user, $identity);
    }
}
