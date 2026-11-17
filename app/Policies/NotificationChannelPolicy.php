<?php

namespace App\Policies;

use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class NotificationChannelPolicy
{
    use OwnsResource;

    public function update(User $user, NotificationChannel $channel): Response
    {
        return $this->owner($user, $channel);
    }

    public function delete(User $user, NotificationChannel $channel): Response
    {
        return $this->owner($user, $channel);
    }
}
