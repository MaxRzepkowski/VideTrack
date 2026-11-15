<?php

namespace App\Policies;

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class ServiceConnectionPolicy
{
    use OwnsResource;

    public function update(User $user, ServiceConnection $connection): Response
    {
        return $this->owner($user, $connection);
    }

    public function delete(User $user, ServiceConnection $connection): Response
    {
        return $this->owner($user, $connection);
    }
}
