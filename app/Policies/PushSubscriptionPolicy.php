<?php

namespace App\Policies;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class PushSubscriptionPolicy
{
    /** Somebody else's browser answers 404, as any resource of another account. */
    public function delete(User $user, PushSubscription $device): Response
    {
        return $device->channel->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
