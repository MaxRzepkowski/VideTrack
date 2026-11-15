<?php

namespace App\Policies;

use App\Models\Play;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class PlayPolicy
{
    use OwnsResource;

    public function update(User $user, Play $play): Response
    {
        return $this->owner($user, $play);
    }

    public function delete(User $user, Play $play): Response
    {
        return $this->owner($user, $play);
    }
}
